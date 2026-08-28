<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/translation_lib.php';
tl_ensure_schema();

$jobId=0;
foreach ($argv as $arg) if (preg_match('/^--job-id=(\d+)$/',$arg,$m)) $jobId=(int)$m[1];
if ($jobId<=0) exit(2);

/**
 * Conservative token estimate for multilingual transcript text.
 * Russian/Cyrillic is deliberately estimated at roughly 2 Unicode chars/token.
 * This overestimates English, which is desirable here because the free Groq TPM
 * limit is more important than squeezing the biggest possible request.
 */
function tl_estimate_text_tokens(string $text): int
{
    $chars = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    return max(1, (int)ceil($chars / 2.0));
}

function tl_estimate_segment_tokens(array $segment): int
{
    // Small allowance for JSON keys/id/punctuation per segment.
    return tl_estimate_text_tokens((string)($segment['text'] ?? '')) + 12;
}

/**
 * Build batches only on transcript-segment boundaries.
 * The value is the estimated SOURCE-text budget, not the whole Groq request.
 * groq_bridge.py separately reserves prompt/schema/output headroom so the full
 * request stays safely below an 8000 TPM per-request ceiling.
 */
function tl_build_token_batches(array $segments, int $maxSourceTokens = 2200): array
{
    $batches = [];
    $current = [];
    $currentTokens = 0;

    foreach ($segments as $segment) {
        $segTokens = tl_estimate_segment_tokens($segment);
        if ($current && ($currentTokens + $segTokens) > $maxSourceTokens) {
            $batches[] = $current;
            $current = [];
            $currentTokens = 0;
        }
        // Never split an individual transcript segment. An unusually huge single
        // segment is sent alone and the provider layer can reject/split only at
        // segment boundaries if necessary.
        $current[] = $segment;
        $currentTokens += $segTokens;
    }
    if ($current) $batches[] = $current;
    return $batches;
}

function tl_is_request_too_large(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, '413')
        || str_contains($m, 'request too large')
        || str_contains($m, 'batch_too_large')
        || (str_contains($m, 'tokens per minute') && str_contains($m, 'requested'));
}


function tl_is_structure_error(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, 'structure_error')
        || str_contains($m, 'json_validate_failed')
        || str_contains($m, 'failed to validate json')
        || str_contains($m, 'нарушила маркеры сегментов')
        || str_contains($m, 'нарушил структуру сегментов');
}
function tl_is_rate_limited(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, '429')
        || str_contains($m, 'rate_limit_exceeded')
        || str_contains($m, 'too many requests');
}

/**
 * Translate a batch without ever cutting a transcript segment.
 * If Groq still reports an oversized request, bisect between whole segments.
 * 429 is retried because sequential batches can temporarily consume the TPM
 * rolling window even when every individual request is below 8000 tokens.
 */
function tl_translate_batch_resilient(
    string $provider,
    array $batch,
    string $sourceLanguage,
    string $targetLanguage,
    array $settings,
    int $rateRetry = 0
): array {
    try {
        return tl_provider_translate_batch($provider, $batch, $sourceLanguage, $targetLanguage, $settings);
    } catch (Throwable $e) {
        if ((tl_is_request_too_large($e) || tl_is_structure_error($e)) && count($batch) > 1) {
            // Never cut transcript text itself. If Groq cannot handle the whole
            // package (token limit or marker/format failure), bisect only between
            // complete transcript segments and retry both halves independently.
            $mid = max(1, intdiv(count($batch), 2));
            $left = array_slice($batch, 0, $mid);
            $right = array_slice($batch, $mid);
            return array_merge(
                tl_translate_batch_resilient($provider, $left, $sourceLanguage, $targetLanguage, $settings),
                tl_translate_batch_resilient($provider, $right, $sourceLanguage, $targetLanguage, $settings)
            );
        }
        if (tl_is_rate_limited($e) && $rateRetry < 4) {
            // Background task: waiting for the rolling TPM window is preferable
            // to failing a long translation halfway through.
            $wait = 15 * ($rateRetry + 1);
            sleep($wait);
            return tl_translate_batch_resilient($provider, $batch, $sourceLanguage, $targetLanguage, $settings, $rateRetry + 1);
        }
        throw $e;
    }
}

$pdo=db();
$claim=$pdo->prepare("UPDATE transcript_translation_jobs SET status='running',progress_percent=1,message='Подготовка текста…',started_at=NOW(),finished_at=NULL WHERE id=? AND status='pending'");
$claim->execute([$jobId]);
if ($claim->rowCount()===0) exit(0);

try {
    $stmt=$pdo->prepare('SELECT * FROM transcript_translation_jobs WHERE id=? LIMIT 1');
    $stmt->execute([$jobId]);
    $job=$stmt->fetch();
    if (!$job) throw new RuntimeException('Задание перевода не найдено.');

    $transcript=tl_transcript_row((int)$job['transcript_id']);
    $sourceLanguage=tl_normalize_language((string)($transcript['language']??''));
    $targetLanguage=tl_normalize_language((string)$job['target_language']);
    if ($sourceLanguage!=='' && $sourceLanguage===$targetLanguage) {
        throw new RuntimeException('Транскрипт уже на выбранном языке.');
    }

    $settings=tl_assert_ready();
    // Freeze provider/model stored when the job was created, while still taking current credentials/path.
    $settings['provider']=(string)$job['provider'];
    $settings['model']=(string)$job['model'];

    $segments=tl_source_segments((int)$job['transcript_id']);
    $count=count($segments);
    $batches=tl_build_token_batches($segments, 2200);
    $translated=[];
    $done=0;

    foreach ($batches as $batchIndex => $batch) {
        $batchCount=count($batch);
        $batchTokens=array_sum(array_map('tl_estimate_segment_tokens', $batch));
        $pct=5+(int)floor(90*($done/max(1,$count)));
        $first=$done+1;
        $last=$done+$batchCount;
        $pdo->prepare('UPDATE transcript_translation_jobs SET progress_percent=?,message=? WHERE id=?')
            ->execute([
                $pct,
                'Перевод сегментов '.$first.'–'.$last.' из '.$count.' · пакет ~'.$batchTokens.' токенов',
                $jobId
            ]);

        $result=tl_translate_batch_resilient((string)$job['provider'],$batch,$sourceLanguage,$targetLanguage,$settings);
        $expected=array_column($batch,'id');
        $got=[];
        foreach ($result as $item) {
            if (!is_array($item)) continue;
            $id=(int)($item['id']??-1);
            $text=trim((string)($item['text']??''));
            if (!in_array($id,$expected,true) || isset($got[$id]) || $text==='') {
                throw new RuntimeException('Сервис нарушил структуру сегментов.');
            }
            $got[$id]=$text;
        }
        foreach ($expected as $id) {
            if (!isset($got[$id])) throw new RuntimeException('Сервис пропустил один из сегментов.');
        }
        foreach ($batch as $src) {
            $translated[]=['id'=>(int)$src['id'],'text'=>$got[(int)$src['id']]];
        }
        $done += $batchCount;
        $pct=5+(int)floor(90*($done/max(1,$count)));
        $pdo->prepare('UPDATE transcript_translation_jobs SET progress_percent=?,message=? WHERE id=?')
            ->execute([$pct,'Переведено '.$done.' из '.$count.' сегментов',$jobId]);
    }

    $translationId=tl_store_translation((int)$job['transcript_id'],(string)$job['provider'],(string)$job['model'],$sourceLanguage,$targetLanguage,$translated);
    $pdo->prepare("UPDATE transcript_translation_jobs SET status='ready',progress_percent=100,message='Перевод готов.',translation_id=?,finished_at=NOW() WHERE id=?")
        ->execute([$translationId,$jobId]);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE transcript_translation_jobs SET status='error',message=?,finished_at=NOW() WHERE id=?")
        ->execute([mb_substr($e->getMessage(),0,4000,'UTF-8'),$jobId]);
    exit(3);
}
