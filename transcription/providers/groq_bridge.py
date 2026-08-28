import argparse
import json
import os
import sys


def fail(message: str, code: int = 1) -> None:
    print(json.dumps({"ok": False, "error": message}, ensure_ascii=False))
    raise SystemExit(code)


def main() -> None:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--file", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--language", default="")
    args = parser.parse_args()

    api_key = os.environ.get("GROQ_API_KEY", "").strip()
    if not api_key:
        fail("GROQ_API_KEY не передан процессу провайдера.")

    try:
        from groq import Groq
    except Exception as exc:
        fail(f"Не удалось импортировать пакет groq: {exc}")

    try:
        client = Groq(api_key=api_key)
        kwargs = {
            "model": args.model,
            "response_format": "verbose_json",
            "timestamp_granularities": ["segment"],
            "temperature": 0.0,
        }
        language = args.language.strip().lower()
        if language:
            kwargs["language"] = language

        with open(args.file, "rb") as audio:
            # The official Groq SDK accepts file-like objects directly.
            result = client.audio.transcriptions.create(file=audio, **kwargs)

        if hasattr(result, "model_dump"):
            payload = result.model_dump()
        elif hasattr(result, "to_dict"):
            payload = result.to_dict()
        else:
            payload = json.loads(str(result))

        print(json.dumps({"ok": True, "result": payload}, ensure_ascii=False))
    except Exception as exc:
        fail(str(exc))


if __name__ == "__main__":
    main()
