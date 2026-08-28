<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/library_identity.php';
require_once __DIR__ . '/screenshot_worker_lib.php';
require_once __DIR__ . '/transcription_lib.php';
require_once __DIR__ . '/translation_lib.php';

function ft_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS file_derivatives (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            library_file_id BIGINT UNSIGNED NOT NULL,
            root_id INT UNSIGNED NOT NULL,
            source_hash CHAR(40) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            relative_path VARCHAR(1600) NOT NULL,
            download_name VARCHAR(1024) NOT NULL,
            start_seconds DECIMAL(12,3) NULL,
            end_seconds DECIMAL(12,3) NULL,
            original_extension VARCHAR(20) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_file_derivatives_file (library_file_id),
            INDEX idx_file_derivatives_source (root_id, source_hash, kind),
            CONSTRAINT fk_file_derivatives_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_derivatives_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS promoted_clips (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            source_library_file_id BIGINT UNSIGNED NOT NULL,
            promoted_library_file_id BIGINT UNSIGNED NOT NULL,
            source_hash CHAR(40) NOT NULL,
            promoted_hash CHAR(40) NOT NULL,
            original_clip_name VARCHAR(1024) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_promoted_clip_file (promoted_library_file_id),
            INDEX idx_promoted_clip_source (source_library_file_id),
            CONSTRAINT fk_promoted_clip_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
            CONSTRAINT fk_promoted_clip_source FOREIGN KEY (source_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_promoted_clip_file FOREIGN KEY (promoted_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS file_tool_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            library_file_id BIGINT UNSIGNED NOT NULL,
            root_id INT UNSIGNED NOT NULL,
            source_hash CHAR(40) NOT NULL,
            action_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            params_json TEXT NULL,
            derivative_id BIGINT UNSIGNED NULL,
            message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_file_tool_jobs_file (library_file_id, status),
            INDEX idx_file_tool_jobs_root (root_id, status),
            CONSTRAINT fk_file_tool_jobs_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_tool_jobs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_tool_jobs_derivative FOREIGN KEY (derivative_id) REFERENCES file_derivatives(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    tr_ensure_schema();
    $done = true;
}

function ft_join_path(string $base, string $child): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . ltrim($child, "\\/");
}

function ft_path_basename(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $pos = strrpos($path, '/');
    return $pos === false ? $path : substr($path, $pos + 1);
}

function ft_path_dirname(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $pos = strrpos($path, '/');
    if ($pos === false) return '.';
    $dir = substr($path, 0, $pos);
    if (preg_match('/^[A-Za-z]:$/', $dir)) $dir .= '/';
    return str_replace('/', DIRECTORY_SEPARATOR, $dir);
}

function ft_file_extension(string $path): string
{
    $name = ft_path_basename($path);
    $pos = strrpos($name, '.');
    return $pos === false ? '' : strtolower(substr($name, $pos + 1));
}

function ft_filename_without_extension(string $path): string
{
    $name = ft_path_basename($path);
    $pos = strrpos($name, '.');
    return $pos === false ? $name : substr($name, 0, $pos);
}

function ft_find_cached_file_by_token(string $token): array
{
    $path = normalize_path(base64url_decode($token));
    if ($path === '') throw new RuntimeException('Файл не указан.');
    $key = li_path_key($path);
    $stmt = db()->prepare(
        'SELECT lf.*, lr.root_path, lr.library_uid
         FROM library_files lf
         INNER JOIN library_roots lr ON lr.id = lf.root_id
         WHERE lf.path_key = ?
         ORDER BY CHAR_LENGTH(lr.root_path) DESC LIMIT 1'
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Файл отсутствует в кэше. Нажмите «Обновить кэш».');
    return $row;
}

function ft_get_cached_file_by_id(int $id): array
{
    $stmt = db()->prepare(
        'SELECT lf.*, lr.root_path, lr.library_uid
         FROM library_files lf
         INNER JOIN library_roots lr ON lr.id = lf.root_id
         WHERE lf.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Исходный видеофайл отсутствует в кэше.');
    return $row;
}

function ft_service_root(array $file): string
{
    return ft_join_path((string)$file['root_path'], VIDEO_SCREENSHOT_DIRNAME);
}

function ft_hash_dir(array $file, ?string $hash = null): string
{
    $hash = $hash ?: (string)$file['file_hash'];
    if (!preg_match('/^[a-f0-9]{40}$/i', $hash)) throw new RuntimeException('Некорректный хэш файла.');
    return ft_join_path(ft_service_root($file), strtolower($hash));
}

function ft_ensure_hash_dir(array $file, ?string $hash = null): string
{
    $dir = ft_hash_dir($file, $hash);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать служебную папку: ' . $dir);
    }
    return $dir;
}

function ft_derivative_absolute_path(array $derivative): string
{
    $stmt = db()->prepare('SELECT root_path FROM library_roots WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$derivative['root_id']]);
    $root = (string)$stmt->fetchColumn();
    if ($root === '') throw new RuntimeException('Корневая библиотека не найдена.');
    $relative = str_replace('/', DIRECTORY_SEPARATOR, (string)$derivative['relative_path']);
    return ft_join_path(ft_join_path($root, VIDEO_SCREENSHOT_DIRNAME), $relative);
}

function ft_safe_name(string $value, string $fallback = 'file'): string
{
    $value = trim($value);
    if ($value === '') $value = $fallback;
    $value = preg_replace('~[<>:"\\/|?*\x00-\x1F]+~u', '_', $value) ?? $fallback;
    $value = preg_replace('/[\. ]+$/u', '', $value) ?? $fallback;
    $value = trim($value);
    if ($value === '') $value = $fallback;
    if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > 180) {
        $value = function_exists('mb_substr') ? mb_substr($value, 0, 180, 'UTF-8') : substr($value, 0, 180);
    }
    return $value;
}

function ft_card_title_for_file(array $file): string
{
    $stmt = db()->prepare('SELECT custom_title FROM file_cards WHERE file_hash = ? LIMIT 1');
    $stmt->execute([(string)$file['file_hash']]);
    $title = trim((string)($stmt->fetchColumn() ?: ''));
    return $title !== '' ? $title : ft_filename_without_extension((string)$file['file_name']);
}

function ft_parse_time(?string $value): ?float
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace(',', '.', $value);
    if (is_numeric($value)) {
        $seconds = (float)$value;
        if ($seconds < 0) throw new RuntimeException('Время не может быть отрицательным.');
        return $seconds;
    }
    $parts = explode(':', $value);
    if (count($parts) > 3 || count($parts) < 2) throw new RuntimeException('Время указывается как секунды, ММ:СС или ЧЧ:ММ:СС.');
    foreach ($parts as $part) {
        if ($part === '' || !is_numeric(str_replace(',', '.', $part))) throw new RuntimeException('Некорректный формат времени.');
    }
    $parts = array_map(static fn($part) => (float)str_replace(',', '.', $part), $parts);
    if (count($parts) === 2) return $parts[0] * 60 + $parts[1];
    return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
}

function ft_validate_interval(?float $start, ?float $end, bool $allowEmpty): void
{
    if (!$allowEmpty && $start === null && $end === null) {
        throw new RuntimeException('Укажите интервал «от» и/или «до».');
    }
    if ($start !== null && $end !== null && $end <= $start) {
        throw new RuntimeException('Время «до» должно быть больше времени «от».');
    }
}

function ft_time_filename(float $seconds): string
{
    $totalMs = (int)round(max(0, $seconds) * 1000);
    $whole = intdiv($totalMs, 1000);
    $ms = $totalMs % 1000;
    $h = intdiv($whole, 3600);
    $m = intdiv($whole % 3600, 60);
    $s = $whole % 60;
    $base = sprintf('%02d-%02d-%02d', $h, $m, $s);
    return $ms ? $base . '-' . str_pad((string)$ms, 3, '0', STR_PAD_LEFT) : $base;
}

function ft_interval_suffix(?float $start, ?float $end): string
{
    if ($start === null && $end === null) return '';
    $from = ft_time_filename($start ?? 0.0);
    $to = $end === null ? 'end' : ft_time_filename($end);
    return '_' . $from . '_' . $to;
}

function ft_browser_playable_extension(string $ext): bool
{
    $supported = defined('BROWSER_PLAYABLE_VIDEO_EXTENSIONS')
        ? BROWSER_PLAYABLE_VIDEO_EXTENSIONS
        : ['mp4', 'm4v', 'webm'];
    return in_array(strtolower($ext), array_map('strtolower', $supported), true);
}

function ft_job_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'action_type' => (string)$row['action_type'],
        'status' => (string)$row['status'],
        'message' => (string)($row['message'] ?? ''),
        'derivative_id' => $row['derivative_id'] ? (int)$row['derivative_id'] : null,
        'created_at' => $row['created_at'] ?? null,
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? null,
    ];
}

function ft_derivative_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'kind' => (string)$row['kind'],
        'download_name' => (string)$row['download_name'],
        'start_seconds' => $row['start_seconds'] !== null ? (float)$row['start_seconds'] : null,
        'end_seconds' => $row['end_seconds'] !== null ? (float)$row['end_seconds'] : null,
        'download_url' => 'derived_media.php?id=' . (int)$row['id'] . '&download=1',
        'inline_url' => 'derived_media.php?id=' . (int)$row['id'] . '&inline=1',
        'created_at' => $row['created_at'] ?? null,
    ];
}

function ft_promoted_clip_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'file_id' => (int)$row['promoted_library_file_id'],
        'file_name' => (string)$row['file_name'],
        'title' => trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : (string)$row['file_name'],
        'token' => base64url_encode((string)$row['file_path']),
        'created_at' => $row['created_at'] ?? null,
    ];
}

function ft_source_clip_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'file_id' => (int)$row['source_library_file_id'],
        'file_name' => (string)$row['file_name'],
        'title' => trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : (string)$row['file_name'],
        'token' => base64url_encode((string)$row['file_path']),
        'created_at' => $row['created_at'] ?? null,
        'original_clip_name' => (string)($row['original_clip_name'] ?? ''),
    ];
}

function ft_status_for_file(array $file): array
{
    ft_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM file_derivatives WHERE library_file_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([(int)$file['id']]);
    $audio = [];
    $clips = [];
    $conversion = null;
    foreach ($stmt->fetchAll() as $row) {
        $path = ft_derivative_absolute_path($row);
        if (!is_file($path)) continue;
        $payload = ft_derivative_payload($row);
        if ($row['kind'] === 'audio') $audio[] = $payload;
        elseif ($row['kind'] === 'clip') $clips[] = $payload;
        elseif ($row['kind'] === 'converted' && $conversion === null) $conversion = $payload;
    }

    $jobsStmt = db()->prepare(
        "SELECT * FROM file_tool_jobs
         WHERE library_file_id = ? AND status IN ('pending','running')
         ORDER BY id"
    );
    $jobsStmt->execute([(int)$file['id']]);
    $jobs = array_map('ft_job_payload', $jobsStmt->fetchAll());

    $lastStmt = db()->prepare(
        "SELECT * FROM file_tool_jobs
         WHERE library_file_id = ? AND status = 'error'
         ORDER BY id DESC LIMIT 1"
    );
    $lastStmt->execute([(int)$file['id']]);
    $lastError = $lastStmt->fetch();

    $promotedStmt = db()->prepare(
        'SELECT pc.*, lf.file_path, lf.file_name, fc.custom_title
         FROM promoted_clips pc
         INNER JOIN library_files lf ON lf.id = pc.promoted_library_file_id
         LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
         WHERE pc.source_library_file_id = ?
         ORDER BY pc.created_at DESC, pc.id DESC'
    );
    $promotedStmt->execute([(int)$file['id']]);
    $promotedClips = array_map('ft_promoted_clip_payload', $promotedStmt->fetchAll());

    $sourceStmt = db()->prepare(
        'SELECT pc.*, lf.file_path, lf.file_name, fc.custom_title
         FROM promoted_clips pc
         INNER JOIN library_files lf ON lf.id = pc.source_library_file_id
         LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
         WHERE pc.promoted_library_file_id = ?
         ORDER BY pc.id DESC LIMIT 1'
    );
    $sourceStmt->execute([(int)$file['id']]);
    $sourceRow = $sourceStmt->fetch();
    $sourceClip = $sourceRow ? ft_source_clip_payload($sourceRow) : null;

    $ext = ft_file_extension((string)$file['file_name']);
    return [
        'file_id' => (int)$file['id'],
        'source_extension' => $ext,
        'browser_playable' => ft_browser_playable_extension($ext),
        'audio' => $audio,
        'transcripts' => tr_list_for_file((int)$file['id']),
        'clips' => $clips,
        'promoted_clips' => $promotedClips,
        'source_clip' => $sourceClip,
        'conversion' => $conversion,
        'jobs' => $jobs,
        'last_error' => $lastError ? (string)$lastError['message'] : '',
    ];
}

function ft_create_job(array $file, string $type, array $params = []): array
{
    ft_ensure_schema();
    if (!in_array($type, ['audio', 'transcript', 'clip', 'convert'], true)) throw new RuntimeException('Неизвестная операция с файлом.');

    if ($type === 'convert') {
        $stmt = db()->prepare(
            "SELECT id FROM file_tool_jobs
             WHERE library_file_id = ? AND action_type = 'convert' AND status IN ('pending','running')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([(int)$file['id']]);
        if ($stmt->fetchColumn()) throw new RuntimeException('Конвертация этого файла уже выполняется.');
    }

    $stmt = db()->prepare(
        'INSERT INTO file_tool_jobs (library_file_id, root_id, source_hash, action_type, status, params_json)
         VALUES (?, ?, ?, ?, \'pending\', ?)'
    );
    $stmt->execute([
        (int)$file['id'], (int)$file['root_id'], (string)$file['file_hash'], $type,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $jobId = (int)db()->lastInsertId();
    ft_launch_job($jobId);
    return ft_get_job($jobId);
}

function ft_get_job(int $jobId): array
{
    ft_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM file_tool_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job) throw new RuntimeException('Задание не найдено.');
    return $job;
}

function ft_launch_job(int $jobId): void
{
    $php = sw_php_cli_path();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'file_tools_worker.php';
    if (!is_file($script)) throw new RuntimeException('Не найден file_tools_worker.php.');

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd.exe /D /S /C start "" /B '
            . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --job-id=' . $jobId . ' >NUL 2>&1';
        $handle = @popen($command, 'r');
        if ($handle === false) throw new RuntimeException('Не удалось запустить фоновую операцию FFmpeg.');
        pclose($handle);
    } else {
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --job-id=' . $jobId . ' >/dev/null 2>&1 &';
        @exec($command, $output, $code);
        if ($code !== 0) throw new RuntimeException('Не удалось запустить фоновую операцию FFmpeg.');
    }
}

function ft_probe_duration(string $source): ?float
{
    $probe = sw_ffprobe_path();
    $command = escapeshellarg($probe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($source);
    $output = [];
    $code = 1;
    @exec($command, $output, $code);
    if ($code !== 0 || !$output) return null;
    $duration = (float)trim((string)$output[0]);
    return $duration > 0 ? $duration : null;
}

function ft_validate_interval_against_duration(?float $start, ?float $end, ?float $duration): void
{
    if ($duration === null) return;
    if ($start !== null && $start >= $duration) throw new RuntimeException('Время «от» находится за пределами видео.');
    if ($end !== null && $end > $duration + 0.25) throw new RuntimeException('Время «до» находится за пределами видео.');
}

function ft_run_process(array $args, int $timeoutSeconds): array
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить FFmpeg.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $pid = 0;

    while (true) {
        $status = proc_get_status($process);
        if ($pid <= 0) $pid = (int)($status['pid'] ?? 0);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        if (!$status['running']) break;
        if (microtime(true) - $started > $timeoutSeconds) {
            if (PHP_OS_FAMILY === 'Windows' && $pid > 0 && function_exists('exec')) {
                @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
            } else {
                @proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            @proc_close($process);
            throw new RuntimeException('FFmpeg превысил допустимое время выполнения.');
        }
        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode === -1 && isset($status['exitcode'])) $exitCode = (int)$status['exitcode'];
    return ['code' => (int)$exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function ft_cleanup_error_text(string $text): string
{
    $text = trim($text);
    if ($text === '') return 'FFmpeg завершился с ошибкой.';
    $lines = preg_split('/\R/u', $text) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn($v) => $v !== ''));
    $tail = array_slice($lines, -8);
    $result = implode(' | ', $tail);
    return mb_strlen($result, 'UTF-8') > 1800 ? mb_substr($result, 0, 1800, 'UTF-8') : $result;
}

function ft_unique_output_path(string $dir, string $base, string $suffix, string $ext): array
{
    $base = ft_safe_name($base);
    $ext = ltrim(strtolower($ext), '.');
    $filename = $base . $suffix . ($ext !== '' ? '.' . $ext : '');
    $candidate = ft_join_path($dir, $filename);
    $n = 2;
    while (is_file($candidate)) {
        $filename = $base . $suffix . '_' . $n . ($ext !== '' ? '.' . $ext : '');
        $candidate = ft_join_path($dir, $filename);
        $n++;
    }
    return [$candidate, $filename];
}

function ft_relative_from_service(array $file, string $absolute): string
{
    $service = rtrim(str_replace('\\', '/', ft_service_root($file)), '/');
    $path = str_replace('\\', '/', normalize_path($absolute));
    if (!str_starts_with(mb_strtolower($path, 'UTF-8'), mb_strtolower($service . '/', 'UTF-8'))) {
        throw new RuntimeException('Производный файл находится вне служебной папки.');
    }
    return substr($path, strlen($service) + 1);
}

function ft_insert_derivative(array $file, string $kind, string $absolutePath, string $downloadName, ?float $start, ?float $end): int
{
    $relative = ft_relative_from_service($file, $absolutePath);
    $stmt = db()->prepare(
        'INSERT INTO file_derivatives
            (library_file_id, root_id, source_hash, kind, relative_path, download_name, start_seconds, end_seconds, original_extension)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$file['id'], (int)$file['root_id'], (string)$file['file_hash'], $kind,
        str_replace('\\', '/', $relative), $downloadName, $start, $end,
        ft_file_extension((string)$file['file_name']),
    ]);
    return (int)db()->lastInsertId();
}

function ft_process_job(int $jobId): void
{
    ft_ensure_schema();
    $pdo = db();
    $claim = $pdo->prepare("UPDATE file_tool_jobs SET status = 'running', started_at = NOW(), message = 'Операция запущена.' WHERE id = ? AND status = 'pending'");
    $claim->execute([$jobId]);
    if ($claim->rowCount() === 0) return;

    try {
        $job = ft_get_job($jobId);
        $file = ft_get_cached_file_by_id((int)$job['library_file_id']);
        $source = (string)$file['file_path'];
        if (!is_file($source)) throw new RuntimeException('Исходный видеофайл не найден на диске.');
        if ((string)$file['file_hash'] !== (string)$job['source_hash']) throw new RuntimeException('Файл изменился после постановки задания. Повторите операцию.');

        $params = json_decode((string)($job['params_json'] ?? '{}'), true);
        if (!is_array($params)) $params = [];
        $start = isset($params['start']) && $params['start'] !== null ? (float)$params['start'] : null;
        $end = isset($params['end']) && $params['end'] !== null ? (float)$params['end'] : null;
        $duration = ft_probe_duration($source);
        ft_validate_interval_against_duration($start, $end, $duration);

        $hashDir = ft_ensure_hash_dir($file);
        $ffmpeg = sw_ffmpeg_path();
        $timeout = defined('FILE_TOOL_FFMPEG_TIMEOUT') ? (int)FILE_TOOL_FFMPEG_TIMEOUT : 21600;
        $type = (string)$job['action_type'];
        $title = ft_card_title_for_file($file);
        $suffix = ft_interval_suffix($start, $end);
        $outputPath = '';
        $downloadName = '';

        if ($type === 'audio' || $type === 'transcript') {
            $format = strtolower(trim((string)($params['format'] ?? 'mp3')));
            if (!in_array($format, ['mp3', 'flac'], true)) throw new RuntimeException('Формат аудио должен быть MP3 или FLAC.');
            $bitrate = (int)($params['bitrate'] ?? 64);
            if (!in_array($bitrate, [64, 96, 192], true)) throw new RuntimeException('Битрейт MP3 должен быть 64, 96 или 192 кбит/с.');
            if ($type === 'transcript') tr_assert_ready();

            [$outputPath, $downloadName] = ft_unique_output_path($hashDir, $title, $suffix, $format);
            $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y'];
            if ($start !== null) { $args[] = '-ss'; $args[] = sprintf('%.3f', $start); }
            $args[] = '-i'; $args[] = $source;
            if ($end !== null) {
                $length = max(0.001, $end - ($start ?? 0.0));
                $args[] = '-t'; $args[] = sprintf('%.3f', $length);
            }
            $args[] = '-map'; $args[] = '0:a:0';
            $args[] = '-vn';
            $args[] = '-ac'; $args[] = '1';
            $args[] = '-ar'; $args[] = '16000';
            if ($format === 'mp3') {
                $args[] = '-c:a'; $args[] = 'libmp3lame';
                $args[] = '-b:a'; $args[] = $bitrate . 'k';
            } else {
                $args[] = '-c:a'; $args[] = 'flac';
                $args[] = '-compression_level'; $args[] = '8';
            }
            $args[] = $outputPath;
            $result = ft_run_process($args, $timeout);
            if ($result['code'] !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
                @unlink($outputPath);
                throw new RuntimeException(ft_cleanup_error_text((string)$result['stderr']));
            }
            $audioDerivativeId = ft_insert_derivative($file, 'audio', $outputPath, $downloadName, $start, $end);

            if ($type === 'audio') {
                $pdo->prepare("UPDATE file_tool_jobs SET status = 'ready', derivative_id = ?, message = 'Готово.', finished_at = NOW() WHERE id = ?")
                    ->execute([$audioDerivativeId, $jobId]);
                return;
            }

            $pdo->prepare("UPDATE file_tool_jobs SET message = 'Аудио готово. Отправка на транскрибацию…' WHERE id = ?")
                ->execute([$jobId]);
            $settings = tr_assert_ready();
            $language = strtolower(trim((string)($params['language'] ?? 'auto')));
            if (!in_array($language, ['auto', 'ru', 'en'], true)) $language = 'auto';
            $transcription = tr_transcribe_audio($outputPath, $settings, $language);

            [$textPath, $textName] = ft_unique_output_path($hashDir, $title, $suffix . '_transcript', 'txt');
            $lines = [];
            $baseOffset = $start ?? 0.0;
            foreach (($transcription['segments'] ?? []) as $segment) {
                $absStart = $baseOffset + (float)($segment['start'] ?? 0);
                $text = trim((string)($segment['text'] ?? ''));
                if ($text !== '') $lines[] = '[' . tr_format_time($absStart) . '] ' . $text;
            }
            if (!$lines) $lines[] = trim((string)($transcription['text'] ?? ''));
            $textBody = implode("\r\n\r\n", $lines) . "\r\n";
            if (@file_put_contents($textPath, $textBody) === false) throw new RuntimeException('Не удалось сохранить TXT-транскрипт.');
            $textDerivativeId = ft_insert_derivative($file, 'transcript', $textPath, $textName, $start, $end);
            try {
                tr_insert_transcript($file, $audioDerivativeId, $textDerivativeId, $transcription, $start, $end);
            } catch (Throwable $e) {
                ft_delete_derivative($textDerivativeId);
                throw $e;
            }
            $pdo->prepare("UPDATE file_tool_jobs SET status = 'ready', derivative_id = ?, message = 'Транскрипт готов.', finished_at = NOW() WHERE id = ?")
                ->execute([$textDerivativeId, $jobId]);
            return;
        }

        if ($type === 'clip') {
            $ext = ft_file_extension($source);
            if ($ext === '') throw new RuntimeException('Не удалось определить формат исходного видео.');
            [$outputPath, $downloadName] = ft_unique_output_path($hashDir, $title, $suffix, $ext);
            $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y'];
            if ($start !== null) { $args[] = '-ss'; $args[] = sprintf('%.3f', $start); }
            $args[] = '-i'; $args[] = $source;
            if ($end !== null) {
                $length = max(0.001, $end - ($start ?? 0.0));
                $args[] = '-t'; $args[] = sprintf('%.3f', $length);
            }
            $args[] = '-map'; $args[] = '0';
            $args[] = '-c'; $args[] = 'copy';
            $args[] = '-avoid_negative_ts'; $args[] = 'make_zero';
            $args[] = $outputPath;
        } elseif ($type === 'convert') {
            [$outputPath, $downloadName] = ft_unique_output_path($hashDir, ft_filename_without_extension((string)$file['file_name']), '_converted', 'mp4');
            $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $source,
                '-map', '0:v:0', '-map', '0:a?', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20',
                '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', $outputPath];
        } else {
            throw new RuntimeException('Неизвестный тип задания.');
        }

        $result = ft_run_process($args, $timeout);
        if ($result['code'] !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException(ft_cleanup_error_text((string)$result['stderr']));
        }

        if ($type === 'convert') {
            $oldStmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE library_file_id = ? AND kind = 'converted'");
            $oldStmt->execute([(int)$file['id']]);
            foreach ($oldStmt->fetchAll() as $old) {
                $oldPath = ft_derivative_absolute_path($old);
                if (is_file($oldPath)) @unlink($oldPath);
                $pdo->prepare('DELETE FROM file_derivatives WHERE id = ?')->execute([(int)$old['id']]);
            }
        }

        $derivativeId = ft_insert_derivative($file, $type === 'convert' ? 'converted' : $type, $outputPath, $downloadName, $start, $end);
        $pdo->prepare("UPDATE file_tool_jobs SET status = 'ready', derivative_id = ?, message = 'Готово.', finished_at = NOW() WHERE id = ?")
            ->execute([$derivativeId, $jobId]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE file_tool_jobs SET status = 'error', message = ?, finished_at = NOW() WHERE id = ?")
            ->execute([$e->getMessage(), $jobId]);
    }
}

function ft_delete_derivative(int $id): void
{
    ft_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM file_derivatives WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return;
    $path = ft_derivative_absolute_path($row);
    if (is_file($path) && !@unlink($path)) throw new RuntimeException('Не удалось удалить файл с диска.');
    db()->prepare('DELETE FROM file_derivatives WHERE id = ?')->execute([$id]);
}

function ft_file_hash(string $path): string
{
    if (!is_file($path)) throw new RuntimeException('Файл для вычисления хэша не найден.');
    $chunkSize = 1024 * 1024;
    $size = filesize($path) ?: 0;
    $ctx = hash_init('sha1');
    hash_update($ctx, 'video-file-v2|' . $size . '|');
    $fp = @fopen($path, 'rb');
    if (!$fp) throw new RuntimeException('Не удалось прочитать файл для вычисления хэша.');
    $first = fread($fp, min($chunkSize, $size));
    if ($first !== false) hash_update($ctx, $first);
    if ($size > $chunkSize) {
        fseek($fp, max(0, $size - $chunkSize));
        $last = fread($fp, $chunkSize);
        if ($last !== false) hash_update($ctx, $last);
    }
    fclose($fp);
    return hash_final($ctx);
}

function ft_relative_from_root(string $root, string $path): string
{
    $rootN = rtrim(str_replace('\\', '/', normalize_path($root)), '/');
    $pathN = str_replace('\\', '/', normalize_path($path));
    if (str_starts_with(mb_strtolower($pathN, 'UTF-8'), mb_strtolower($rootN . '/', 'UTF-8'))) {
        return substr($pathN, strlen($rootN) + 1);
    }
    return ft_path_basename($pathN);
}

function ft_move_directory_contents(string $from, string $to): void
{
    if (!is_dir($from)) return;
    if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) throw new RuntimeException('Не удалось создать новую служебную папку.');
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $src = ft_join_path($from, $name);
        $dst = ft_join_path($to, $name);
        if (is_dir($src)) {
            ft_move_directory_contents($src, $dst);
            @rmdir($src);
        } else {
            if (file_exists($dst)) @unlink($dst);
            if (!@rename($src, $dst)) throw new RuntimeException('Не удалось перенести служебный файл: ' . $name);
        }
    }
    @rmdir($from);
}

function ft_remove_recursive(string $path): bool
{
    if (!file_exists($path)) return true;
    if (is_file($path) || is_link($path)) return @unlink($path);
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!ft_remove_recursive(ft_join_path($path, $name))) return false;
    }
    return @rmdir($path);
}

function ft_same_nullable_time(?float $a, ?float $b, float $epsilon = 0.250): bool
{
    if ($a === null || $b === null) return $a === null && $b === null;
    return abs($a - $b) <= $epsilon;
}

function ft_derivative_matches_clip_interval(array $row, ?float $clipStart, ?float $clipEnd): bool
{
    $rs = $row['start_seconds'] !== null ? (float)$row['start_seconds'] : null;
    $re = $row['end_seconds'] !== null ? (float)$row['end_seconds'] : null;
    if (ft_same_nullable_time($rs, $clipStart) && ft_same_nullable_time($re, $clipEnd)) return true;

    // Compatibility fallback for older derivatives where interval metadata may
    // differ slightly or be incomplete, but the generated filename still
    // contains the canonical interval suffix.
    if ($clipStart !== null || $clipEnd !== null) {
        $suffix = ft_interval_suffix($clipStart, $clipEnd);
        $name = (string)($row['download_name'] ?? '');
        if ($suffix !== '' && stripos($name, $suffix) !== false) return true;
    }
    return false;
}

function ft_move_derivative_to_promoted_file(array $derivative, array $newFile, string $newHashDir, ?float $clipStart, ?float $clipEnd): void
{
    $oldPath = ft_derivative_absolute_path($derivative);
    if (!is_file($oldPath)) return;
    $name = ft_path_basename($oldPath);
    $newPath = ft_join_path($newHashDir, $name);
    if (file_exists($newPath)) {
        [$newPath, $name] = ft_unique_output_path($newHashDir, ft_filename_without_extension($name), '', ft_file_extension($name));
    }
    if (!@rename($oldPath, $newPath)) {
        if (!@copy($oldPath, $newPath) || !@unlink($oldPath)) throw new RuntimeException('Не удалось перенести связанный файл: ' . $name);
    }
    $relative = ft_relative_from_service($newFile, $newPath);
    $duration = ($clipEnd !== null) ? max(0.0, $clipEnd - ($clipStart ?? 0.0)) : null;
    db()->prepare(
        'UPDATE file_derivatives SET library_file_id=?, root_id=?, source_hash=?, relative_path=?, start_seconds=?, end_seconds=? WHERE id=?'
    )->execute([
        (int)$newFile['id'], (int)$newFile['root_id'], (string)$newFile['file_hash'], str_replace('\\','/',$relative),
        0.0, $duration, (int)$derivative['id']
    ]);
}

function ft_promote_clip(int $derivativeId): array
{
    ft_ensure_schema();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE id=? AND kind='clip' LIMIT 1");
    $stmt->execute([$derivativeId]);
    $clip = $stmt->fetch();
    if (!$clip) throw new RuntimeException('Фрагмент не найден.');

    $sourceFile = ft_get_cached_file_by_id((int)$clip['library_file_id']);
    $clipPath = ft_derivative_absolute_path($clip);
    if (!is_file($clipPath)) throw new RuntimeException('Файл фрагмента отсутствует на диске.');

    $active = $pdo->prepare("SELECT COUNT(*) FROM file_tool_jobs WHERE library_file_id=? AND status IN ('pending','running')");
    $active->execute([(int)$sourceFile['id']]);
    if ((int)$active->fetchColumn() > 0) throw new RuntimeException('Дождитесь завершения текущих операций с исходным видео.');

    $start = $clip['start_seconds'] !== null ? (float)$clip['start_seconds'] : null;
    $end = $clip['end_seconds'] !== null ? (float)$clip['end_seconds'] : null;
    $rootPath = (string)$sourceFile['root_path'];
    $fileName = (string)$clip['download_name'];
    $destination = ft_join_path($rootPath, $fileName);
    if (file_exists($destination)) throw new RuntimeException('В корне библиотеки уже существует файл «' . $fileName . '». Переименуйте или удалите его.');

    $newHash = ft_file_hash($clipPath);
    $hashConflict = $pdo->prepare('SELECT id FROM library_files WHERE root_id=? AND file_hash=? LIMIT 1');
    $hashConflict->execute([(int)$sourceFile['root_id'], $newHash]);
    if ($hashConflict->fetchColumn()) throw new RuntimeException('В библиотеке уже есть видео с таким же содержимым.');

    // Determine related audio/transcripts before moving anything.
    $relatedAudio = [];
    $audioStmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE library_file_id=? AND kind='audio'");
    $audioStmt->execute([(int)$sourceFile['id']]);
    foreach ($audioStmt->fetchAll() as $row) {
        if (ft_derivative_matches_clip_interval($row, $start, $end)) {
            $relatedAudio[(int)$row['id']] = $row;
        }
    }

    $relatedTranscripts = [];
    $trStmt = $pdo->prepare('SELECT * FROM file_transcripts WHERE library_file_id=?');
    $trStmt->execute([(int)$sourceFile['id']]);
    foreach ($trStmt->fetchAll() as $tr) {
        $ts = $tr['start_seconds'] !== null ? (float)$tr['start_seconds'] : null;
        $te = $tr['end_seconds'] !== null ? (float)$tr['end_seconds'] : null;
        if (ft_same_nullable_time($ts,$start) && ft_same_nullable_time($te,$end)) {
            $relatedTranscripts[(int)$tr['id']] = $tr;
            if (!empty($tr['audio_derivative_id'])) {
                $aid = (int)$tr['audio_derivative_id'];
                if (!isset($relatedAudio[$aid])) {
                    $a = $pdo->prepare("SELECT * FROM file_derivatives WHERE id=? AND kind='audio' LIMIT 1");
                    $a->execute([$aid]);
                    if ($r=$a->fetch()) $relatedAudio[$aid]=$r;
                }
            }
        }
    }

    if ($relatedTranscripts) {
        $ids = implode(',', array_map('intval', array_keys($relatedTranscripts)));
        $busyTr = (int)$pdo->query("SELECT COUNT(*) FROM transcript_translation_jobs WHERE transcript_id IN ($ids) AND status IN ('pending','running')")->fetchColumn();
        if ($busyTr > 0) throw new RuntimeException('Дождитесь завершения перевода связанного транскрипта.');
    }

    if (!@rename($clipPath, $destination)) {
        if (!@copy($clipPath, $destination) || !@unlink($clipPath)) throw new RuntimeException('Не удалось перенести фрагмент в корень библиотеки.');
    }

    $newFileId = 0;
    $movedDerivativeIds = [];
    try {
        clearstatcache(true, $destination);
        $size = (int)(filesize($destination) ?: 0);
        $mtime = (int)(filemtime($destination) ?: time());
        $relative = ft_relative_from_root($rootPath, $destination);
        $pathKey = li_path_key($destination);

        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO library_files (root_id,relative_path,file_path,path_key,file_name,file_hash,file_size,file_mtime,last_scan_token) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute([(int)$sourceFile['root_id'],$relative,$destination,$pathKey,$fileName,$newHash,$size,$mtime,'']);
        $newFileId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO file_cards (file_path,file_hash) VALUES (?,?)')->execute([$destination,$newHash]);
        $pdo->prepare('INSERT INTO promoted_clips (root_id,source_library_file_id,promoted_library_file_id,source_hash,promoted_hash,original_clip_name) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$sourceFile['root_id'],(int)$sourceFile['id'],$newFileId,(string)$sourceFile['file_hash'],$newHash,$fileName]);
        $pdo->commit();

        $newFile = ft_get_cached_file_by_id($newFileId);
        $newHashDir = ft_ensure_hash_dir($newFile, $newHash);

        foreach ($relatedAudio as $id=>$der) {
            ft_move_derivative_to_promoted_file($der,$newFile,$newHashDir,$start,$end);
            $movedDerivativeIds[$id]=true;
        }

        foreach ($relatedTranscripts as $trId=>$tr) {
            $textId = (int)$tr['text_derivative_id'];
            if ($textId && !isset($movedDerivativeIds[$textId])) {
                $q=$pdo->prepare('SELECT * FROM file_derivatives WHERE id=? LIMIT 1'); $q->execute([$textId]);
                if ($der=$q->fetch()) { ft_move_derivative_to_promoted_file($der,$newFile,$newHashDir,$start,$end); $movedDerivativeIds[$textId]=true; }
            }
            $shift = $start ?? 0.0;
            $duration = $end !== null ? max(0.0,$end-$shift) : null;
            $pdo->prepare('UPDATE file_transcripts SET library_file_id=?,root_id=?,source_hash=?,start_seconds=0,end_seconds=? WHERE id=?')
                ->execute([$newFileId,(int)$sourceFile['root_id'],$newHash,$duration,$trId]);
            if ($shift > 0) {
                $pdo->prepare('UPDATE file_transcript_segments SET start_seconds=GREATEST(0,start_seconds-?), end_seconds=GREATEST(0,end_seconds-?) WHERE transcript_id=?')
                    ->execute([$shift,$shift,$trId]);
                $tlq=$pdo->prepare('SELECT id FROM file_transcript_translations WHERE transcript_id=?'); $tlq->execute([$trId]);
                $translationIds=array_map('intval',$tlq->fetchAll(PDO::FETCH_COLUMN));
                if ($translationIds) {
                    $list=implode(',',$translationIds);
                    $pdo->prepare("UPDATE file_transcript_translation_segments SET start_seconds=IF(start_seconds IS NULL,NULL,GREATEST(0,start_seconds-?)), end_seconds=IF(end_seconds IS NULL,NULL,GREATEST(0,end_seconds-?)) WHERE translation_id IN ($list)")
                        ->execute([$shift,$shift]);
                }
            }
            tr_rebuild_transcript_text($trId);
        }

        // Final repair pass for audio linked from moved transcripts. This is
        // deliberately done after transcript reassignment so legacy records
        // cannot leave the audio attached to the source video.
        foreach ($relatedTranscripts as $trId => $tr) {
            $audioId = !empty($tr['audio_derivative_id']) ? (int)$tr['audio_derivative_id'] : 0;
            if ($audioId <= 0) continue;
            $checkAudio = $pdo->prepare("SELECT * FROM file_derivatives WHERE id=? AND kind='audio' LIMIT 1");
            $checkAudio->execute([$audioId]);
            $audioRow = $checkAudio->fetch();
            if (!$audioRow) continue;
            if ((int)$audioRow['library_file_id'] !== $newFileId) {
                ft_move_derivative_to_promoted_file($audioRow, $newFile, $newHashDir, $start, $end);
                $movedDerivativeIds[$audioId] = true;
            }
        }

        // Verify every audio derivative selected for this clip is now owned by
        // the promoted video and physically exists in its service directory.
        foreach (array_keys($relatedAudio) as $audioId) {
            $verify = $pdo->prepare("SELECT * FROM file_derivatives WHERE id=? AND kind='audio' LIMIT 1");
            $verify->execute([(int)$audioId]);
            $audioRow = $verify->fetch();
            if (!$audioRow) continue;
            if ((int)$audioRow['library_file_id'] !== $newFileId || !is_file(ft_derivative_absolute_path($audioRow))) {
                throw new RuntimeException('Не удалось перенести связанную аудиодорожку к новому видео.');
            }
        }

        // The clip itself is no longer a derivative; it is the new library file.
        $pdo->prepare('DELETE FROM file_derivatives WHERE id=?')->execute([$derivativeId]);

        // Queue the standard 10-frame cache for the promoted video.
        $expected = defined('VIDEO_SCREENSHOT_COUNT') ? (int)VIDEO_SCREENSHOT_COUNT : 10;
        $pdo->prepare("INSERT INTO root_video_screenshot_sets (root_id,file_hash,status,expected_count,source_file_size,source_file_mtime,last_error,thumbnail_sort_order,duration_seconds)\n                       VALUES (?,?,'pending',?,?,?,NULL,NULL,NULL)\n                       ON DUPLICATE KEY UPDATE status='pending',expected_count=VALUES(expected_count),source_file_size=VALUES(source_file_size),source_file_mtime=VALUES(source_file_mtime),last_error=NULL")
            ->execute([(int)$sourceFile['root_id'],$newHash,$expected,$size,$mtime]);
        try { sw_launch_worker((int)$sourceFile['root_id'], false); } catch (Throwable $ignored) {}

        return [
            'token'=>base64url_encode($destination),
            'file_id'=>$newFileId,
            'file_name'=>$fileName,
            'file_path'=>$destination,
            'file_hash'=>$newHash,
            'moved_audio_count'=>count($relatedAudio),
            'moved_transcript_count'=>count($relatedTranscripts),
        ];
    } catch (Throwable $e) {
        // If DB registration failed before the new row became valid, put the clip back.
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newFileId <= 0 && is_file($destination) && !is_file($clipPath)) @rename($destination,$clipPath);
        throw $e;
    }
}

function ft_finalize_conversion(int $derivativeId): array
{
    ft_ensure_schema();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE id = ? AND kind = 'converted' LIMIT 1");
    $stmt->execute([$derivativeId]);
    $derivative = $stmt->fetch();
    if (!$derivative) throw new RuntimeException('Сконвертированный MP4 не найден.');

    $file = ft_get_cached_file_by_id((int)$derivative['library_file_id']);
    if ((string)$file['file_hash'] !== (string)$derivative['source_hash']) {
        throw new RuntimeException('Исходный файл изменился после конвертации. Выполните конвертацию заново.');
    }

    $duplicates = $pdo->prepare('SELECT COUNT(*) FROM library_files WHERE root_id = ? AND file_hash = ? AND id <> ?');
    $duplicates->execute([(int)$file['root_id'], (string)$file['file_hash'], (int)$file['id']]);
    if ((int)$duplicates->fetchColumn() > 0) {
        throw new RuntimeException('Нельзя заменить исходник автоматически: в этой библиотеке есть еще один файл с тем же хэшем.');
    }

    $converted = ft_derivative_absolute_path($derivative);
    if (!is_file($converted)) throw new RuntimeException('Сконвертированный MP4 отсутствует на диске.');
    $original = (string)$file['file_path'];
    if (!is_file($original)) throw new RuntimeException('Исходный файл отсутствует на диске.');

    $dir = ft_path_dirname($original);
    $base = ft_filename_without_extension($original);
    $destination = ft_join_path($dir, $base . '.mp4');
    if (li_path_key($destination) !== li_path_key($original) && file_exists($destination)) {
        throw new RuntimeException('Рядом уже существует файл ' . ft_path_basename($destination) . '. Удалите или переименуйте его.');
    }

    $oldHash = (string)$file['file_hash'];
    $newHash = ft_file_hash($converted);

    $sameConverted = $pdo->prepare('SELECT COUNT(*) FROM library_files WHERE file_hash = ? AND id <> ?');
    $sameConverted->execute([$newHash, (int)$file['id']]);
    if ((int)$sameConverted->fetchColumn() > 0) {
        throw new RuntimeException('Нельзя заменить исходник автоматически: в каталоге уже есть файл с содержимым, совпадающим со сконвертированным MP4.');
    }
    $cardConflict = $pdo->prepare('SELECT id FROM file_cards WHERE file_hash = ? AND file_hash <> ? LIMIT 1');
    $cardConflict->execute([$newHash, $oldHash]);
    if ($cardConflict->fetchColumn()) {
        throw new RuntimeException('Новый MP4 конфликтует с уже существующей карточкой по хэшу.');
    }

    $oldHashDir = ft_hash_dir($file, $oldHash);
    $newHashDir = ft_hash_dir($file, $newHash);
    if ($oldHash !== $newHash && is_dir($newHashDir)) {
        $setCheck = $pdo->prepare('SELECT COUNT(*) FROM root_video_screenshot_sets WHERE root_id = ? AND file_hash = ?');
        $setCheck->execute([(int)$file['root_id'], $newHash]);
        $derCheck = $pdo->prepare('SELECT COUNT(*) FROM file_derivatives WHERE root_id = ? AND source_hash = ? AND library_file_id <> ?');
        $derCheck->execute([(int)$file['root_id'], $newHash, (int)$file['id']]);
        if ((int)$setCheck->fetchColumn() > 0 || (int)$derCheck->fetchColumn() > 0) {
            throw new RuntimeException('Служебная папка нового MP4 уже используется другой записью.');
        }
        if (!ft_remove_recursive($newHashDir)) {
            throw new RuntimeException('Не удалось очистить старую служебную папку нового хэша.');
        }
    }

    $backup = $original . '.video_catalog_delete_' . bin2hex(random_bytes(5));
    $serviceMoved = false;
    $dbCommitted = false;

    if (!@rename($original, $backup)) throw new RuntimeException('Не удалось подготовить исходный файл к замене.');
    try {
        if (!@rename($converted, $destination)) {
            throw new RuntimeException('Не удалось перенести сконвертированный MP4 на место исходного файла.');
        }

        if ($oldHash !== $newHash && is_dir($oldHashDir)) {
            if (!@rename($oldHashDir, $newHashDir)) {
                throw new RuntimeException('Не удалось перенести служебные файлы на новый хэш.');
            }
            $serviceMoved = true;
        }

        clearstatcache(true, $destination);
        $newSize = (int)(filesize($destination) ?: 0);
        $newMtime = (int)(filemtime($destination) ?: time());
        $relative = ft_relative_from_root((string)$file['root_path'], $destination);
        $newKey = li_path_key($destination);
        $newName = ft_path_basename($destination);

        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE library_files SET relative_path = ?, file_path = ?, path_key = ?, file_name = ?, file_hash = ?, file_size = ?, file_mtime = ?, last_seen_at = NOW() WHERE id = ?'
        )->execute([$relative, $destination, $newKey, $newName, $newHash, $newSize, $newMtime, (int)$file['id']]);

        $pdo->prepare('UPDATE file_cards SET file_path = ?, file_hash = ? WHERE file_hash = ?')
            ->execute([$destination, $newHash, $oldHash]);

        $pdo->prepare('UPDATE root_video_screenshot_sets SET file_hash = ?, source_file_size = ?, source_file_mtime = ? WHERE root_id = ? AND file_hash = ?')
            ->execute([$newHash, $newSize, $newMtime, (int)$file['root_id'], $oldHash]);
        $pdo->prepare("UPDATE root_video_screenshots SET file_hash = ?, relative_path = REPLACE(relative_path, CONCAT(?, '/'), CONCAT(?, '/')) WHERE root_id = ? AND file_hash = ?")
            ->execute([$newHash, $oldHash, $newHash, (int)$file['root_id'], $oldHash]);
        $pdo->prepare("UPDATE file_derivatives SET source_hash = ?, relative_path = REPLACE(relative_path, CONCAT(?, '/'), CONCAT(?, '/')) WHERE library_file_id = ?")
            ->execute([$newHash, $oldHash, $newHash, (int)$file['id']]);
        $pdo->prepare('UPDATE file_transcripts SET source_hash = ? WHERE library_file_id = ?')
            ->execute([$newHash, (int)$file['id']]);
        $pdo->prepare('DELETE FROM file_derivatives WHERE id = ?')->execute([$derivativeId]);
        $pdo->commit();
        $dbCommitted = true;

        // Исходник держим во временном имени до тех пор, пока файловая и БД-части операции не завершены.
        @unlink($backup);

        return [
            'token' => base64url_encode($destination),
            'file_path' => $destination,
            'file_name' => $newName,
            'file_hash' => $newHash,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) @ $pdo->rollBack();
        if (!$dbCommitted) {
            if ($serviceMoved && is_dir($newHashDir) && !is_dir($oldHashDir)) @rename($newHashDir, $oldHashDir);
            if (is_file($destination) && !is_file($converted)) @rename($destination, $converted);
            if (is_file($backup) && !is_file($original)) @rename($backup, $original);
        }
        throw $e;
    }
}

function ft_resolve_view_url(array $file): array
{
    ft_ensure_schema();
    $pdo = db();

    // Primary lookup: the current cached library row.
    $stmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE library_file_id = ? AND kind = 'converted' ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$file['id']]);
    $converted = $stmt->fetch();

    // A cache refresh may recreate library_files rows while the physical source
    // and its stable content hash stay the same.  In that case do not fall back
    // to AVI/MKV/TS (which browsers may play as audio-only): recover the
    // converted derivative by stable root_id + source_hash.
    if (!$converted) {
        $stmt = $pdo->prepare("SELECT * FROM file_derivatives WHERE root_id = ? AND source_hash = ? AND kind = 'converted' ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$file['root_id'], (string)$file['file_hash']]);
        $converted = $stmt->fetch();
        if ($converted && (int)$converted['library_file_id'] !== (int)$file['id']) {
            try {
                $pdo->prepare('UPDATE file_derivatives SET library_file_id = ? WHERE id = ?')
                    ->execute([(int)$file['id'], (int)$converted['id']]);
                $converted['library_file_id'] = (int)$file['id'];
            } catch (Throwable $e) {
                // The derivative itself is still valid; playback does not need
                // the repair to succeed immediately.
            }
        }
    }

    // Last-resort recovery for old databases where the DB row was removed by a
    // cascading cache delete but the actual *_converted.mp4 remains beside the
    // screenshots. Re-register the newest physical converted file.
    if (!$converted) {
        try {
            $hashDir = ft_hash_dir($file);
            if (is_dir($hashDir)) {
                $matches = glob(rtrim($hashDir, "\\/") . DIRECTORY_SEPARATOR . '*_converted*.mp4') ?: [];
                $matches = array_values(array_filter($matches, 'is_file'));
                if ($matches) {
                    usort($matches, static function (string $a, string $b): int {
                        return ((int)@filemtime($b)) <=> ((int)@filemtime($a));
                    });
                    $path = $matches[0];
                    $downloadName = basename($path);
                    $relative = ft_relative_from_service($file, $path);
                    $insert = $pdo->prepare(
                        "INSERT INTO file_derivatives\n                            (library_file_id, root_id, source_hash, kind, relative_path, download_name, start_seconds, end_seconds, original_extension)\n                         VALUES (?, ?, ?, 'converted', ?, ?, NULL, NULL, ?)"
                    );
                    $insert->execute([
                        (int)$file['id'],
                        (int)$file['root_id'],
                        (string)$file['file_hash'],
                        str_replace('\\', '/', $relative),
                        $downloadName,
                        ft_file_extension((string)$file['file_name']),
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $stmt = $pdo->prepare('SELECT * FROM file_derivatives WHERE id = ? LIMIT 1');
                    $stmt->execute([$newId]);
                    $converted = $stmt->fetch();
                }
            }
        } catch (Throwable $e) {
            // Recovery is best-effort. If nothing can be recovered, use the
            // original source below just as before.
        }
    }

    if ($converted) {
        $path = ft_derivative_absolute_path($converted);
        if (is_file($path)) {
            return [
                'url' => 'derived_media.php?id=' . (int)$converted['id'] . '&inline=1',
                'converted' => true,
                'name' => (string)$converted['download_name'],
            ];
        }
    }

    return [
        'url' => 'media.php?token=' . rawurlencode(base64url_encode((string)$file['file_path'])),
        'converted' => false,
        'name' => (string)$file['file_name'],
    ];
}
