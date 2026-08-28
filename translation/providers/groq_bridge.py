import argparse
import json
import math
import os
import re
from pathlib import Path

# Groq free/on-demand TPM limit can be 8000. Keep comfortable headroom for
# prompt + output + tokenizer differences.
SAFE_REQUEST_TOKEN_BUDGET = 7000
PROMPT_TOKEN_RESERVE = 420
MAX_COMPLETION_TOKEN_CAP = 3600
MIN_COMPLETION_TOKENS = 256


def emit_error(message: str, code: int = 1, *, error_code: str = "", status=None, retry_after=None):
    payload = {"ok": False, "error": message}
    if error_code:
        payload["code"] = error_code
    if status is not None:
        payload["status"] = status
    if retry_after is not None:
        payload["retry_after"] = retry_after
    print(json.dumps(payload, ensure_ascii=False))
    raise SystemExit(code)


def estimate_text_tokens(text: str) -> int:
    # Deliberately pessimistic for Russian/Cyrillic.
    return max(1, math.ceil(len(text) / 2.0))


def estimate_request(items) -> tuple[int, int, int]:
    source_tokens = sum(estimate_text_tokens(str(x.get("text") or "")) + 10 for x in items)
    estimated_input = source_tokens + PROMPT_TOKEN_RESERVE
    completion_tokens = max(MIN_COMPLETION_TOKENS, math.ceil(source_tokens * 1.45) + 160)
    completion_tokens = min(MAX_COMPLETION_TOKEN_CAP, completion_tokens)
    estimated_total = estimated_input + completion_tokens
    return estimated_input, completion_tokens, estimated_total


def marker_begin(ident: int) -> str:
    return f"<<<VCSEG:{ident}:BEGIN>>>"


def marker_end(ident: int) -> str:
    return f"<<<VCSEG:{ident}:END>>>"


def build_marked_input(items) -> str:
    parts = []
    for item in items:
        ident = int(item["id"])
        text = str(item.get("text") or "")
        parts.append(f"{marker_begin(ident)}\n{text}\n{marker_end(ident)}")
    return "\n\n".join(parts)


def parse_marked_output(content: str, ids: list[int]):
    result = {}
    for ident in ids:
        pattern = re.escape(marker_begin(ident)) + r"\s*(.*?)\s*" + re.escape(marker_end(ident))
        matches = re.findall(pattern, content, flags=re.DOTALL)
        if len(matches) != 1:
            return None
        text = matches[0].strip()
        if not text:
            return None
        result[ident] = text

    # Reject foreign/duplicated VCSEG blocks. This protects one-to-one alignment.
    found_ids = [int(x) for x in re.findall(r"<<<VCSEG:(\d+):BEGIN>>>", content)]
    if found_ids != ids:
        return None

    return [{"id": ident, "text": result[ident]} for ident in ids]


def clean_single_fallback(content: str) -> str:
    text = content.strip()
    # If model wrapped a single translation in a code fence, unwrap it.
    if text.startswith("```") and text.endswith("```"):
        lines = text.splitlines()
        if len(lines) >= 3:
            text = "\n".join(lines[1:-1]).strip()
    # Remove one pair of surrounding quotes, but do not otherwise normalize text.
    if len(text) >= 2 and text[0] == text[-1] and text[0] in {'"', "'"}:
        text = text[1:-1].strip()
    return text


def main():
    p = argparse.ArgumentParser(add_help=False)
    p.add_argument("--input", required=True)
    p.add_argument("--model", required=True)
    args = p.parse_args()

    api_key = os.environ.get("GROQ_API_KEY", "").strip()
    if not api_key:
        emit_error("GROQ_API_KEY не передан процессу провайдера.")

    try:
        from groq import Groq
    except Exception as exc:
        emit_error(f"Не удалось импортировать пакет groq: {exc}")

    try:
        payload = json.loads(Path(args.input).read_text(encoding="utf-8"))
        items = payload.get("items") or []
        src = (payload.get("source_language") or "").strip().lower()
        dst = (payload.get("target_language") or "").strip().lower()
        if not items or not dst:
            emit_error("Пустой пакет перевода.")

        estimated_input, completion_tokens, estimated_total = estimate_request(items)
        if estimated_total > SAFE_REQUEST_TOKEN_BUDGET:
            emit_error(
                f"BATCH_TOO_LARGE: оценка запроса ~{estimated_total} токенов "
                f"(вход ~{estimated_input}, резерв ответа {completion_tokens}); пакет нужно разделить между сегментами.",
                error_code="batch_too_large",
            )

        ids = [int(x["id"]) for x in items]
        lang_names = {"ru": "Russian", "en": "English"}
        source_name = lang_names.get(src, src or "the source language")
        target_name = lang_names.get(dst, dst)

        # Plain text marker protocol is more robust here than Structured Outputs:
        # we validate the exact set/order of segment markers ourselves, and the PHP
        # worker recursively splits a failed batch between whole transcript segments.
        marked_input = build_marked_input(items)
        prompt = f"""Translate the transcript segments from {source_name} to {target_name}.

STRICT RULES:
1. Translate EACH segment independently. Never merge two segments and never split one segment into several segments.
2. Do not omit, reorder, summarize, explain, rewrite, or add information.
3. Keep every marker EXACTLY unchanged and in exactly the same order.
4. Output only the marked translated segments. No preface, no comments, no Markdown fences.
5. Translate only the text BETWEEN BEGIN and END markers. Never translate the markers themselves.
6. Preserve sentence boundaries and punctuation as closely as natural {target_name} permits.

INPUT:
{marked_input}
"""

        client = Groq(api_key=api_key)
        response = client.chat.completions.create(
            model=args.model,
            messages=[{"role": "user", "content": prompt}],
            temperature=0,
            max_completion_tokens=completion_tokens,
            reasoning_effort="low",
            reasoning_format="hidden",
        )

        content = (response.choices[0].message.content or "").strip()
        translations = parse_marked_output(content, ids)

        # With one segment there is no possibility of cross-segment merging. If the
        # model forgets the wrapper, accept the whole response as the translation.
        if translations is None and len(ids) == 1:
            fallback = clean_single_fallback(content)
            if fallback:
                translations = [{"id": ids[0], "text": fallback}]

        if translations is None:
            emit_error(
                "STRUCTURE_ERROR: модель нарушила маркеры сегментов; пакет нужно разделить и повторить.",
                error_code="structure_error",
            )

        print(json.dumps({
            "ok": True,
            "translations": translations,
            "estimated_request_tokens": estimated_total,
        }, ensure_ascii=False))

    except SystemExit:
        raise
    except Exception as exc:
        status = getattr(exc, "status_code", None)
        retry_after = None
        response = getattr(exc, "response", None)
        try:
            if response is not None and getattr(response, "headers", None) is not None:
                retry_after = response.headers.get("retry-after") or response.headers.get("x-ratelimit-reset-tokens")
        except Exception:
            retry_after = None
        emit_error(str(exc), status=status, retry_after=retry_after)


if __name__ == "__main__":
    main()
