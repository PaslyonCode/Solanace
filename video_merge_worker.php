<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/video_merge_lib.php';

vm_ensure_schema();

$jobId = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--job-id=(\d+)$/', $arg, $m)) $jobId = (int)$m[1];
}
if ($jobId <= 0) exit(2);

function vmw_update_job(int $jobId, string $status, ?string $message = null, ?int $outputFileId = null): void
{
    if ($status === 'running') {
        db()->prepare("UPDATE video_merge_jobs SET status='running', message=?, started_at=COALESCE(started_at,NOW()), finished_at=NULL, heartbeat_at=NOW() WHERE id=?")
            ->execute([$message, $jobId]);
        return;
    }
    if ($status === 'ready') {
        db()->prepare("UPDATE video_merge_jobs SET status='ready', message=?, output_library_file_id=?, progress_percent=100, progress_stage='Готово', heartbeat_at=NOW(), finished_at=NOW() WHERE id=?")
            ->execute([$message, $outputFileId, $jobId]);
        return;
    }
    db()->prepare("UPDATE video_merge_jobs SET status='error', message=?, progress_stage='Ошибка', heartbeat_at=NOW(), finished_at=NOW() WHERE id=?")
        ->execute([$message, $jobId]);
}

function vmw_update_progress(int $jobId, float $percent, string $stage, string $message, float $seconds = 0, float $total = 0): void
{
    $percent = max(0, min(99, (int)round($percent)));
    db()->prepare(
        "UPDATE video_merge_jobs
         SET status='running', progress_percent=?, progress_stage=?, message=?, progress_seconds=?, progress_total_seconds=?,
             heartbeat_at=NOW(), started_at=COALESCE(started_at,NOW()), finished_at=NULL
         WHERE id=?"
    )->execute([$percent, $stage, $message, max(0, $seconds), max(0, $total), $jobId]);
}

function vmw_probe(string $path): array
{
    $args = [sw_ffprobe_path(), '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $path];
    $result = ft_run_process($args, 120);
    if ($result['code'] !== 0) throw new RuntimeException('FFprobe: ' . ft_cleanup_error_text($result['stderr']));
    $json = json_decode($result['stdout'], true);
    if (!is_array($json)) throw new RuntimeException('FFprobe вернул некорректный ответ.');
    $video = null;
    $audio = null;
    foreach (($json['streams'] ?? []) as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && $video === null) $video = $stream;
        if (($stream['codec_type'] ?? '') === 'audio' && $audio === null) $audio = $stream;
    }
    if (!$video) throw new RuntimeException('В файле нет видеодорожки: ' . vm_basename($path));
    return [
        'video' => $video,
        'audio' => $audio,
        'duration' => (float)($json['format']['duration'] ?? $video['duration'] ?? 0),
    ];
}

function vmw_stream_value(?array $stream, string $key): string
{
    if (!$stream) return '';
    $value = $stream[$key] ?? '';
    return is_scalar($value) ? (string)$value : '';
}

function vmw_fast_compatible(array $probes): bool
{
    if (count($probes) < 2) return false;
    $first = $probes[0];
    $v0 = $first['video'];
    $a0 = $first['audio'];
    if (vmw_stream_value($v0, 'codec_name') !== 'h264') return false;
    if ($a0 && vmw_stream_value($a0, 'codec_name') !== 'aac') return false;

    $vKeys = ['codec_name', 'width', 'height', 'pix_fmt', 'avg_frame_rate', 'profile', 'level'];
    $aKeys = ['codec_name', 'sample_rate', 'channels', 'channel_layout'];
    foreach (array_slice($probes, 1) as $probe) {
        $v = $probe['video'];
        $a = $probe['audio'];
        if (($a0 === null) !== ($a === null)) return false;
        foreach ($vKeys as $key) if (vmw_stream_value($v0, $key) !== vmw_stream_value($v, $key)) return false;
        if ($a0 && $a) foreach ($aKeys as $key) if (vmw_stream_value($a0, $key) !== vmw_stream_value($a, $key)) return false;
    }
    return true;
}

function vmw_even(int $value): int
{
    $value = max(2, $value);
    return $value % 2 === 0 ? $value : $value - 1;
}

function vmw_target_size(array $probes, string $resolution): array
{
    if ($resolution === '1920x1080') return [1920, 1080];
    if ($resolution === '1280x720') return [1280, 720];

    $bestW = 1280;
    $bestH = 720;
    $bestArea = 0;
    foreach ($probes as $probe) {
        $w = (int)($probe['video']['width'] ?? 0);
        $h = (int)($probe['video']['height'] ?? 0);
        if ($w <= 0 || $h <= 0) continue;
        $area = $w * $h;
        if ($area > $bestArea) {
            $bestArea = $area;
            $bestW = $w;
            $bestH = $h;
        }
    }
    $scale = min(1.0, 1920 / max(1, $bestW), 1080 / max(1, $bestH));
    return [vmw_even((int)round($bestW * $scale)), vmw_even((int)round($bestH * $scale))];
}

function vmw_concat_line(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = str_replace("'", "'\\''", $path);
    return "file '" . $path . "'\n";
}

function vmw_write_concat_list(string $path, array $files): void
{
    $content = '';
    foreach ($files as $file) $content .= vmw_concat_line($file);
    if (@file_put_contents($path, $content) === false) throw new RuntimeException('Не удалось создать служебный список для FFmpeg.');
}

function vmw_parse_progress_time(array $values): float
{
    if (isset($values['out_time_us']) && is_numeric($values['out_time_us'])) {
        return max(0, (float)$values['out_time_us'] / 1000000.0);
    }
    if (!empty($values['out_time']) && preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', (string)$values['out_time'], $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (float)$m[3];
    }
    return 0.0;
}

function vmw_run_ffmpeg_progress(array $args, int $timeoutSeconds, ?callable $onProgress = null): array
{
    if (!$args) throw new RuntimeException('Пустая команда FFmpeg.');

    /*
     * В Windows неблокирующее чтение anonymous pipe от proc_open() ненадежно:
     * stream_get_contents() может фактически ждать завершения дочернего процесса.
     * Поэтому прогресс FFmpeg пишется в обычный временный файл, который worker
     * опрашивает независимо от stdout/stderr процесса.
     */
    $tempDir = rtrim(sys_get_temp_dir(), "\\/");
    $token = 'videocat_merge_' . getmypid() . '_' . bin2hex(random_bytes(5));
    $progressPath = $tempDir . DIRECTORY_SEPARATOR . $token . '_progress.txt';
    $stdoutPath = $tempDir . DIRECTORY_SEPARATOR . $token . '_stdout.log';
    $stderrPath = $tempDir . DIRECTORY_SEPARATOR . $token . '_stderr.log';

    @file_put_contents($progressPath, '');
    @file_put_contents($stdoutPath, '');
    @file_put_contents($stderrPath, '');

    array_splice($args, 1, 0, [
        '-nostdin',
        '-stats_period', '0.5',
        '-progress', $progressPath,
        '-nostats',
    ]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'ab'],
        2 => ['file', $stderrPath, 'ab'],
    ];

    // Передаем команду массивом, чтобы Windows не перепарсивал кавычки через cmd.exe.
    $process = @proc_open($args, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        @unlink($progressPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);
        throw new RuntimeException('Не удалось запустить FFmpeg.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);

    $started = microtime(true);
    $lastCallback = 0.0;
    $lastSeconds = 0.0;
    $lastProgressLength = 0;
    $progressBuffer = '';
    $progressValues = [];
    $pid = 0;
    $status = ['exitcode' => -1];

    $consumeProgress = static function (string $chunk) use (&$progressBuffer, &$progressValues, &$lastCallback, &$lastSeconds, $onProgress): void {
        if ($chunk !== '') $progressBuffer .= str_replace("\r\n", "\n", $chunk);
        while (($pos = strpos($progressBuffer, "\n")) !== false) {
            $line = trim(substr($progressBuffer, 0, $pos));
            $progressBuffer = substr($progressBuffer, $pos + 1);
            if ($line === '' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $progressValues[$key] = $value;
            if ($key === 'progress') {
                $seconds = vmw_parse_progress_time($progressValues);
                $now = microtime(true);
                if ($onProgress && ($value === 'end' || $now - $lastCallback >= 0.55 || abs($seconds - $lastSeconds) >= 1.0)) {
                    $lastCallback = $now;
                    $lastSeconds = $seconds;
                    $onProgress($seconds, $value === 'end');
                }
                $progressValues = [];
            }
        }
    };

    $readNewProgress = static function () use ($progressPath, &$lastProgressLength, $consumeProgress): void {
        clearstatcache(true, $progressPath);
        if (!is_file($progressPath)) return;
        $size = @filesize($progressPath);
        if ($size === false || $size <= $lastProgressLength) return;
        $fp = @fopen($progressPath, 'rb');
        if (!$fp) return;
        if ($lastProgressLength > 0) @fseek($fp, $lastProgressLength);
        $chunk = stream_get_contents($fp);
        fclose($fp);
        if ($chunk === false || $chunk === '') return;
        $lastProgressLength += strlen($chunk);
        $consumeProgress($chunk);
    };

    try {
        while (true) {
            $status = proc_get_status($process);
            if ($pid <= 0) $pid = (int)($status['pid'] ?? 0);

            $readNewProgress();

            if (!$status['running']) break;
            if (microtime(true) - $started > $timeoutSeconds) {
                if (PHP_OS_FAMILY === 'Windows' && $pid > 0 && function_exists('exec')) {
                    @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
                } else {
                    @proc_terminate($process, 9);
                }
                throw new RuntimeException('FFmpeg превысил допустимое время выполнения.');
            }
            usleep(200000);
        }

        // FFmpeg уже завершился, дочитываем последнюю порцию progress=end.
        $readNewProgress();
        usleep(50000);
        $readNewProgress();

        $exitCode = proc_close($process);
        $process = null;
        if ($exitCode === -1 && isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
            $exitCode = (int)$status['exitcode'];
        }

        $stdout = (string)(@file_get_contents($stdoutPath) ?: '');
        $stderr = (string)(@file_get_contents($stderrPath) ?: '');
        return ['code' => (int)$exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    } finally {
        if (is_resource($process)) @proc_close($process);
        @unlink($progressPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}

function vmw_run_ffmpeg(array $args, int $timeout): array
{
    $result = vmw_run_ffmpeg_progress($args, $timeout, null);
    if ($result['code'] !== 0) throw new RuntimeException(ft_cleanup_error_text($result['stderr']));
    return $result;
}

function vmw_remove_tree(string $path): void
{
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    $items = @scandir($path);
    if ($items !== false) foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        vmw_remove_tree(vm_join_path($path, $item));
    }
    @rmdir($path);
}

function vmw_reencode_segments(int $jobId, array $sources, array $probes, string $jobDir, string $aspect, string $quality, string $resolution, float $totalDuration): array
{
    [$width, $height] = vmw_target_size($probes, $resolution);
    $qualityMap = [
        'high' => ['crf' => '18', 'preset' => 'medium'],
        'normal' => ['crf' => '21', 'preset' => 'medium'],
        'compact' => ['crf' => '24', 'preset' => 'fast'],
    ];
    $q = $qualityMap[$quality] ?? $qualityMap['normal'];
    $segments = [];
    $timeout = defined('FILE_TOOL_FFMPEG_TIMEOUT') ? (int)FILE_TOOL_FFMPEG_TIMEOUT : 21600;
    $completedDuration = 0.0;
    $count = count($sources);

    foreach ($sources as $index => $source) {
        $probe = $probes[$index];
        $sourceDuration = max(0.001, (float)($probe['duration'] ?? 0));
        $segment = vm_join_path($jobDir, sprintf('segment_%04d.mp4', $index + 1));
        $filter = $aspect === 'crop'
            ? "scale={$width}:{$height}:force_original_aspect_ratio=increase:force_divisible_by=2,crop={$width}:{$height},setsar=1,fps=30,format=yuv420p"
            : "scale={$width}:{$height}:force_original_aspect_ratio=decrease:force_divisible_by=2,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=30,format=yuv420p";

        $args = [sw_ffmpeg_path(), '-y', '-hide_banner', '-loglevel', 'error', '-i', (string)$source['file_path']];
        if ($probe['audio']) {
            array_push($args,
                '-map', '0:v:0', '-map', '0:a:0',
                '-vf', $filter,
                '-af', 'aresample=48000,aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo',
                '-c:v', 'libx264', '-preset', $q['preset'], '-crf', $q['crf'],
                '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2',
                '-movflags', '+faststart', $segment
            );
        } else {
            array_push($args,
                '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
                '-map', '0:v:0', '-map', '1:a:0',
                '-vf', $filter,
                '-c:v', 'libx264', '-preset', $q['preset'], '-crf', $q['crf'],
                '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2',
                '-shortest', '-movflags', '+faststart', $segment
            );
        }

        $label = 'Перекодирование ' . ($index + 1) . '/' . $count;
        $name = (string)($source['file_name'] ?? vm_basename((string)$source['file_path']));
        vmw_update_progress($jobId, 5 + (84 * ($completedDuration / max(0.001, $totalDuration))), $label, $name, $completedDuration, $totalDuration);
        $result = vmw_run_ffmpeg_progress($args, $timeout, static function (float $seconds) use ($jobId, $completedDuration, $sourceDuration, $totalDuration, $label, $name): void {
            $inside = min($sourceDuration, max(0, $seconds));
            $done = $completedDuration + $inside;
            $percent = 5 + 84 * ($done / max(0.001, $totalDuration));
            vmw_update_progress($jobId, $percent, $label, $name, $done, $totalDuration);
        });
        if ($result['code'] !== 0) throw new RuntimeException(ft_cleanup_error_text($result['stderr']));
        if (!is_file($segment) || filesize($segment) === 0) throw new RuntimeException('FFmpeg не создал промежуточный сегмент.');
        $segments[] = $segment;
        $completedDuration += $sourceDuration;
    }
    return [$segments, $width, $height];
}

function vmw_insert_output_cache(array $root, string $outputPath, array $sources, string $outputName): int
{
    $rootId = (int)$root['id'];
    $hash = vm_file_hash($outputPath);
    $size = (int)(filesize($outputPath) ?: 0);
    $mtime = (int)(filemtime($outputPath) ?: time());
    $relative = vm_relative_path((string)$root['root_path'], $outputPath);
    $pathKey = vm_path_key($outputPath);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO library_files
                (root_id, relative_path, file_path, path_key, file_name, file_hash, file_size, file_mtime, last_scan_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$rootId, $relative, normalize_path($outputPath), $pathKey, $outputName, $hash, $size, $mtime, '']);
        $fileId = (int)$pdo->lastInsertId();

        $mergeStmt = $pdo->prepare(
            'INSERT INTO video_merges (root_id, output_library_file_id, output_file_hash, output_name)
             VALUES (?, ?, ?, ?)'
        );
        $mergeStmt->execute([$rootId, $fileId, $hash, $outputName]);
        $mergeId = (int)$pdo->lastInsertId();

        $srcStmt = $pdo->prepare(
            'INSERT INTO video_merge_sources
                (merge_id, source_order, source_library_file_id, source_file_hash, source_file_name, source_relative_path)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($sources as $index => $source) {
            $srcStmt->execute([
                $mergeId, $index + 1, (int)$source['id'], (string)$source['file_hash'],
                (string)$source['file_name'], (string)$source['relative_path'],
            ]);
        }

        $pdo->prepare(
            "INSERT INTO root_video_screenshot_sets
                (root_id, file_hash, status, expected_count, source_file_size, source_file_mtime, last_error, thumbnail_sort_order)
             VALUES (?, ?, 'pending', ?, ?, ?, NULL, 1)
             ON DUPLICATE KEY UPDATE status='pending', expected_count=VALUES(expected_count),
                source_file_size=VALUES(source_file_size), source_file_mtime=VALUES(source_file_mtime), last_error=NULL"
        )->execute([$rootId, $hash, defined('VIDEO_SCREENSHOT_COUNT') ? VIDEO_SCREENSHOT_COUNT : 10, $size, $mtime]);

        $pdo->prepare('UPDATE library_roots SET last_refresh_at = NOW() WHERE id = ?')->execute([$rootId]);
        $pdo->commit();
        return $fileId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$jobDir = null;
$finalOutputPath = null;
try {
    $job = vm_get_job($jobId);
    if (!in_array($job['status'], ['pending', 'running'], true)) exit(0);
    vmw_update_progress($jobId, 1, 'Подготовка', 'Проверяю параметры исходных видео…');

    $params = json_decode((string)$job['params_json'], true);
    if (!is_array($params)) throw new RuntimeException('Повреждены параметры задания склейки.');
    $rootStmt = db()->prepare('SELECT * FROM library_roots WHERE id = ? LIMIT 1');
    $rootStmt->execute([(int)$job['root_id']]);
    $root = $rootStmt->fetch();
    if (!$root) throw new RuntimeException('Библиотека больше не существует.');

    $sources = [];
    $sourceStmt = db()->prepare('SELECT * FROM library_files WHERE id = ? AND root_id = ? LIMIT 1');
    foreach (($params['sources'] ?? []) as $src) {
        $sourceStmt->execute([(int)($src['id'] ?? 0), (int)$root['id']]);
        $file = $sourceStmt->fetch();
        if (!$file || !is_file((string)$file['file_path'])) {
            throw new RuntimeException('Один из исходных файлов больше недоступен: ' . (string)($src['file_name'] ?? 'неизвестный файл'));
        }
        $sources[] = $file;
    }
    if (count($sources) < 2) throw new RuntimeException('Недостаточно исходных видео.');

    $outputName = vm_safe_output_name((string)$job['output_name']);
    $finalOutputPath = vm_join_path((string)$root['root_path'], $outputName);
    if (file_exists($finalOutputPath)) throw new RuntimeException('Выходной файл уже существует: ' . $outputName);

    $serviceRoot = vm_join_path((string)$root['root_path'], VIDEO_SCREENSHOT_DIRNAME);
    if (!is_dir($serviceRoot) && !@mkdir($serviceRoot, 0775, true) && !is_dir($serviceRoot)) {
        throw new RuntimeException('Не удалось создать служебную папку библиотеки.');
    }
    $jobDir = vm_join_path($serviceRoot, '_merge_job_' . $jobId);
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException('Не удалось создать временную папку склейки.');
    }

    $probes = [];
    $sourceCount = count($sources);
    foreach ($sources as $index => $source) {
        $probes[] = vmw_probe((string)$source['file_path']);
        vmw_update_progress(
            $jobId,
            1 + (4 * (($index + 1) / max(1, $sourceCount))),
            'Анализ',
            'Проверено видео ' . ($index + 1) . '/' . $sourceCount . ': ' . (string)$source['file_name']
        );
    }
    $totalDuration = array_sum(array_map(static fn(array $probe): float => max(0, (float)($probe['duration'] ?? 0)), $probes));
    if ($totalDuration <= 0) $totalDuration = (float)max(1, $sourceCount);
    $mode = (string)($params['mode'] ?? 'auto');
    $resolution = (string)($params['resolution'] ?? 'auto');
    $aspect = (string)($params['aspect'] ?? 'fit');
    $quality = (string)($params['quality'] ?? 'normal');
    $timeout = defined('FILE_TOOL_FFMPEG_TIMEOUT') ? (int)FILE_TOOL_FFMPEG_TIMEOUT : 21600;
    $tempOutput = vm_join_path($jobDir, 'result.mp4');
    $usedFast = false;

    if ($mode === 'auto' && $resolution === 'auto' && vmw_fast_compatible($probes)) {
        vmw_update_progress($jobId, 5, 'Быстрая склейка', 'Параметры совместимы. Склеиваю без перекодирования…', 0, $totalDuration);
        $list = vm_join_path($jobDir, 'concat_fast.txt');
        vmw_write_concat_list($list, array_map(static fn(array $s) => (string)$s['file_path'], $sources));
        $result = vmw_run_ffmpeg_progress([
            sw_ffmpeg_path(), '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0', '-i', $list,
            '-map', '0:v:0', '-map', '0:a?', '-c', 'copy', '-movflags', '+faststart', $tempOutput,
        ], $timeout, static function (float $seconds) use ($jobId, $totalDuration): void {
            $done = min($totalDuration, max(0, $seconds));
            vmw_update_progress($jobId, 5 + 92 * ($done / max(0.001, $totalDuration)), 'Быстрая склейка', 'Склеиваю совместимые потоки без перекодирования…', $done, $totalDuration);
        });
        $usedFast = $result['code'] === 0 && is_file($tempOutput) && filesize($tempOutput) > 0;
        if (!$usedFast) @unlink($tempOutput);
    }

    if (!$usedFast) {
        vmw_update_progress($jobId, 5, 'Перекодирование', 'Привожу видео к единому формату…', 0, $totalDuration);
        [$segments, $targetW, $targetH] = vmw_reencode_segments($jobId, $sources, $probes, $jobDir, $aspect, $quality, $resolution, $totalDuration);
        vmw_update_progress($jobId, 90, 'Финальная склейка', 'Склеиваю подготовленные сегменты…', 0, $totalDuration);
        $list = vm_join_path($jobDir, 'concat_segments.txt');
        vmw_write_concat_list($list, $segments);
        $result = vmw_run_ffmpeg_progress([
            sw_ffmpeg_path(), '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0', '-i', $list,
            '-c', 'copy', '-movflags', '+faststart', $tempOutput,
        ], $timeout, static function (float $seconds) use ($jobId, $totalDuration): void {
            $done = min($totalDuration, max(0, $seconds));
            vmw_update_progress($jobId, 90 + 7 * ($done / max(0.001, $totalDuration)), 'Финальная склейка', 'Склеиваю подготовленные сегменты…', $done, $totalDuration);
        });
        if ($result['code'] !== 0) throw new RuntimeException(ft_cleanup_error_text($result['stderr']));
    }

    vmw_update_progress($jobId, 98, 'Завершение', 'FFmpeg завершен. Переношу итоговый файл в библиотеку…', $totalDuration, $totalDuration);
    if (!is_file($tempOutput) || filesize($tempOutput) === 0) throw new RuntimeException('FFmpeg не создал итоговое видео.');
    if (!@rename($tempOutput, $finalOutputPath)) {
        if (!@copy($tempOutput, $finalOutputPath) || !@unlink($tempOutput)) throw new RuntimeException('Не удалось перенести итоговое видео в корень библиотеки.');
    }

    vmw_update_progress($jobId, 99, 'Кэширование', 'Добавляю итоговое видео в кэш библиотеки…', $totalDuration, $totalDuration);
    $outputFileId = vmw_insert_output_cache($root, $finalOutputPath, $sources, $outputName);
    $shotMessage = '';
    try {
        sw_launch_worker((int)$root['id']);
        $shotMessage = ' Кадры поставлены в очередь.';
    } catch (Throwable $e) {
        $shotMessage = ' Видео создано, но worker кадров не запущен: ' . $e->getMessage();
    }
    $message = $usedFast
        ? 'Готово: выполнена быстрая склейка без перекодирования.' . $shotMessage
        : 'Готово: исходники приведены к единому формату и перекодированы.' . $shotMessage;
    vmw_update_job($jobId, 'ready', $message, $outputFileId);
    vmw_remove_tree($jobDir);
    exit(0);
} catch (Throwable $e) {
    if ($finalOutputPath && is_file($finalOutputPath)) {
        $check = db()->prepare('SELECT COUNT(*) FROM library_files WHERE path_key = ?');
        $check->execute([vm_path_key($finalOutputPath)]);
        if ((int)$check->fetchColumn() === 0) @unlink($finalOutputPath);
    }
    if ($jobDir) vmw_remove_tree($jobDir);
    vmw_update_job($jobId, 'error', $e->getMessage());
    exit(3);
}
