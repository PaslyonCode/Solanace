<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/library_identity.php';
require_once __DIR__ . '/screenshot_worker_lib.php';
require_once __DIR__ . '/file_tools_lib.php';

function vm_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_merge_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            output_name VARCHAR(1024) NOT NULL,
            params_json MEDIUMTEXT NOT NULL,
            output_library_file_id BIGINT UNSIGNED NULL,
            message TEXT NULL,
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            progress_stage VARCHAR(80) NULL,
            progress_seconds DOUBLE NOT NULL DEFAULT 0,
            progress_total_seconds DOUBLE NOT NULL DEFAULT 0,
            heartbeat_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_video_merge_jobs_root (root_id, status),
            CONSTRAINT fk_video_merge_jobs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_merge_jobs_output FOREIGN KEY (output_library_file_id) REFERENCES library_files(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $progressColumns = [
        'progress_percent' => "ALTER TABLE video_merge_jobs ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER message",
        'progress_stage' => "ALTER TABLE video_merge_jobs ADD COLUMN progress_stage VARCHAR(80) NULL AFTER progress_percent",
        'progress_seconds' => "ALTER TABLE video_merge_jobs ADD COLUMN progress_seconds DOUBLE NOT NULL DEFAULT 0 AFTER progress_stage",
        'progress_total_seconds' => "ALTER TABLE video_merge_jobs ADD COLUMN progress_total_seconds DOUBLE NOT NULL DEFAULT 0 AFTER progress_seconds",
        'heartbeat_at' => "ALTER TABLE video_merge_jobs ADD COLUMN heartbeat_at DATETIME NULL AFTER progress_total_seconds",
    ];
    foreach ($progressColumns as $column => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM video_merge_jobs LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) $pdo->exec($sql);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_merges (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            output_library_file_id BIGINT UNSIGNED NOT NULL,
            output_file_hash CHAR(40) NOT NULL,
            output_name VARCHAR(1024) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_video_merges_output (output_library_file_id),
            INDEX idx_video_merges_root_hash (root_id, output_file_hash),
            CONSTRAINT fk_video_merges_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_merges_output FOREIGN KEY (output_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_merge_sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            merge_id BIGINT UNSIGNED NOT NULL,
            source_order INT UNSIGNED NOT NULL,
            source_library_file_id BIGINT UNSIGNED NULL,
            source_file_hash CHAR(40) NOT NULL,
            source_file_name VARCHAR(1024) NOT NULL,
            source_relative_path TEXT NOT NULL,
            UNIQUE KEY uq_video_merge_source_order (merge_id, source_order),
            INDEX idx_video_merge_source_file (source_library_file_id),
            INDEX idx_video_merge_source_hash (source_file_hash),
            CONSTRAINT fk_video_merge_sources_merge FOREIGN KEY (merge_id) REFERENCES video_merges(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_merge_sources_file FOREIGN KEY (source_library_file_id) REFERENCES library_files(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function vm_root_by_path(string $requestedPath): array
{
    $root = li_resolve_root($requestedPath, false);
    if (!$root) throw new RuntimeException('Библиотека не найдена.');
    return $root;
}

function vm_normalize_for_key(string $path): string
{
    $path = normalize_path($path);
    $path = rtrim(str_replace('/', '\\', $path), "\\/");
    if (preg_match('/^[A-Za-z]:$/', $path)) $path .= '\\';
    if (DIRECTORY_SEPARATOR === '\\') $path = mb_strtolower($path, 'UTF-8');
    return $path;
}

function vm_path_key(string $path): string
{
    return sha1(vm_normalize_for_key($path));
}

function vm_join_path(string $base, string $child): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . ltrim($child, "\\/");
}

function vm_relative_path(string $rootPath, string $path): string
{
    $root = str_replace('\\', '/', rtrim(normalize_path($rootPath), "\\/"));
    $path = str_replace('\\', '/', normalize_path($path));
    $prefix = $root . '/';
    $cmpPath = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($path, 'UTF-8') : $path;
    $cmpPrefix = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($prefix, 'UTF-8') : $prefix;
    if (str_starts_with($cmpPath, $cmpPrefix)) return ltrim(substr($path, strlen($prefix)), '/');
    return basename($path);
}

function vm_basename(string $path): string
{
    $parts = preg_split('~[\\\\/]~', rtrim($path, "\\/"));
    return $parts ? (string)end($parts) : $path;
}

function vm_file_hash(string $path): string
{
    $path = normalize_path($path);
    if (!is_file($path)) return sha1('missing|' . vm_path_key($path));
    $chunkSize = 1024 * 1024;
    $size = filesize($path) ?: 0;
    $ctx = hash_init('sha1');
    hash_update($ctx, 'video-file-v2|' . $size . '|');
    $fp = @fopen($path, 'rb');
    if (!$fp) return sha1('unreadable|' . vm_path_key($path) . '|' . $size);
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

function vm_safe_output_name(string $name): string
{
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Укажите название выходного видео.');
    $name = preg_replace('~[<>:"/\\\\|?*\x00-\x1F]+~u', '_', $name) ?? '';
    $name = preg_replace('/[\. ]+$/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Некорректное название выходного видео.');
    if (preg_match('/\.mp4$/i', $name)) $name = preg_replace('/\.mp4$/i', '', $name) ?? $name;
    if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 180) {
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 180, 'UTF-8') : substr($name, 0, 180);
    }
    return $name . '.mp4';
}

function vm_files_from_paths(int $rootId, array $paths): array
{
    if (count($paths) < 2) throw new RuntimeException('Для склейки выберите минимум два видео.');
    $stmt = db()->prepare(
        'SELECT lf.*, lr.root_path
         FROM library_files lf
         INNER JOIN library_roots lr ON lr.id = lf.root_id
         WHERE lf.root_id = ? AND lf.path_key = ? LIMIT 1'
    );
    $files = [];
    $seen = [];
    foreach ($paths as $path) {
        $path = normalize_path((string)$path);
        $key = vm_path_key($path);
        if (isset($seen[$key])) continue;
        $stmt->execute([$rootId, $key]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Видео отсутствует в кэше: ' . vm_basename($path));
        if (!is_file((string)$row['file_path'])) throw new RuntimeException('Видео отсутствует на диске: ' . (string)$row['file_name']);
        $files[] = $row;
        $seen[$key] = true;
    }
    if (count($files) < 2) throw new RuntimeException('Для склейки выберите минимум два разных видео.');
    return $files;
}

function vm_launch_job(int $jobId): void
{
    $php = sw_php_cli_path();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'video_merge_worker.php';
    if (!is_file($script)) throw new RuntimeException('Не найден video_merge_worker.php.');

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd.exe /D /S /C start "" /B '
            . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --job-id=' . $jobId . ' >NUL 2>&1';
        $handle = @popen($command, 'r');
        if ($handle === false) throw new RuntimeException('Не удалось запустить фоновую склейку.');
        pclose($handle);
    } else {
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --job-id=' . $jobId . ' >/dev/null 2>&1 &';
        @exec($command, $output, $code);
        if ($code !== 0) throw new RuntimeException('Не удалось запустить фоновую склейку.');
    }
}

function vm_create_job(array $root, array $paths, string $outputName, array $options): array
{
    vm_ensure_schema();
    $files = vm_files_from_paths((int)$root['id'], $paths);
    $outputName = vm_safe_output_name($outputName);
    $outputPath = vm_join_path((string)$root['root_path'], $outputName);
    if (file_exists($outputPath)) throw new RuntimeException('В корне библиотеки уже есть файл «' . $outputName . '».');

    $mode = strtolower((string)($options['mode'] ?? 'auto'));
    if (!in_array($mode, ['auto', 'reencode'], true)) $mode = 'auto';
    $resolution = strtolower((string)($options['resolution'] ?? 'auto'));
    if (!in_array($resolution, ['auto', '1920x1080', '1280x720'], true)) $resolution = 'auto';
    $aspect = strtolower((string)($options['aspect'] ?? 'fit'));
    if (!in_array($aspect, ['fit', 'crop'], true)) $aspect = 'fit';
    $quality = strtolower((string)($options['quality'] ?? 'normal'));
    if (!in_array($quality, ['high', 'normal', 'compact'], true)) $quality = 'normal';

    $sources = array_map(static fn(array $file): array => [
        'id' => (int)$file['id'],
        'file_hash' => (string)$file['file_hash'],
        'file_name' => (string)$file['file_name'],
        'relative_path' => (string)$file['relative_path'],
    ], $files);

    $params = [
        'sources' => $sources,
        'mode' => $mode,
        'resolution' => $resolution,
        'aspect' => $aspect,
        'quality' => $quality,
    ];
    $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Не удалось подготовить параметры склейки.');

    $stmt = db()->prepare(
        'INSERT INTO video_merge_jobs (root_id, status, output_name, params_json)
         VALUES (?, \'pending\', ?, ?)'
    );
    $stmt->execute([(int)$root['id'], $outputName, $json]);
    $jobId = (int)db()->lastInsertId();
    try {
        vm_launch_job($jobId);
    } catch (Throwable $e) {
        db()->prepare("UPDATE video_merge_jobs SET status='error', message=?, finished_at=NOW() WHERE id=?")
            ->execute([$e->getMessage(), $jobId]);
        throw $e;
    }
    return vm_get_job($jobId);
}

function vm_get_job(int $jobId): array
{
    vm_ensure_schema();
    if ($jobId <= 0) throw new RuntimeException('Задание склейки не указано.');
    $stmt = db()->prepare('SELECT * FROM video_merge_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Задание склейки не найдено.');
    return $row;
}

function vm_active_jobs_for_root(int $rootId): array
{
    vm_ensure_schema();
    $stmt = db()->prepare(
        "SELECT * FROM video_merge_jobs
         WHERE root_id = ? AND status IN ('pending','running')
         ORDER BY id DESC"
    );
    $stmt->execute([$rootId]);
    return array_map('vm_job_payload', $stmt->fetchAll());
}

function vm_job_payload(array $job): array
{
    return [
        'id' => (int)$job['id'],
        'status' => (string)$job['status'],
        'output_name' => (string)$job['output_name'],
        'output_library_file_id' => $job['output_library_file_id'] ? (int)$job['output_library_file_id'] : null,
        'message' => (string)($job['message'] ?? ''),
        'progress_percent' => max(0, min(100, (int)($job['progress_percent'] ?? 0))),
        'progress_stage' => (string)($job['progress_stage'] ?? ''),
        'progress_seconds' => (float)($job['progress_seconds'] ?? 0),
        'progress_total_seconds' => (float)($job['progress_total_seconds'] ?? 0),
        'heartbeat_at' => $job['heartbeat_at'] ?? null,
        'heartbeat_age_seconds' => !empty($job['heartbeat_at']) ? max(0, time() - (int)strtotime((string)$job['heartbeat_at'])) : null,
        'created_at' => $job['created_at'] ?? null,
        'started_at' => $job['started_at'] ?? null,
        'finished_at' => $job['finished_at'] ?? null,
    ];
}

function vm_find_file_by_token(string $token): ?array
{
    $path = normalize_path(base64url_decode($token));
    if ($path === '') return null;
    $stmt = db()->prepare(
        'SELECT lf.*, lr.root_path
         FROM library_files lf
         INNER JOIN library_roots lr ON lr.id = lf.root_id
         WHERE lf.path_key = ? ORDER BY CHAR_LENGTH(lr.root_path) DESC LIMIT 1'
    );
    $stmt->execute([vm_path_key($path)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vm_card_info(string $token): array
{
    vm_ensure_schema();
    $file = vm_find_file_by_token($token);
    if (!$file) return ['is_merge' => false, 'sources' => []];

    $stmt = db()->prepare(
        'SELECT * FROM video_merges
         WHERE output_library_file_id = ? OR (root_id = ? AND output_file_hash = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$file['id'], (int)$file['root_id'], (string)$file['file_hash']]);
    $merge = $stmt->fetch();
    if (!$merge) return ['is_merge' => false, 'sources' => []];

    $srcStmt = db()->prepare('SELECT * FROM video_merge_sources WHERE merge_id = ? ORDER BY source_order');
    $srcStmt->execute([(int)$merge['id']]);
    $rows = $srcStmt->fetchAll();
    $resolveById = db()->prepare('SELECT * FROM library_files WHERE id = ? AND root_id = ? LIMIT 1');
    $resolveByHash = db()->prepare('SELECT * FROM library_files WHERE root_id = ? AND file_hash = ? ORDER BY id LIMIT 1');
    $titleStmt = db()->prepare('SELECT custom_title FROM file_cards WHERE file_hash = ? LIMIT 1');

    $sources = [];
    foreach ($rows as $row) {
        $current = null;
        if (!empty($row['source_library_file_id'])) {
            $resolveById->execute([(int)$row['source_library_file_id'], (int)$merge['root_id']]);
            $current = $resolveById->fetch() ?: null;
        }
        if (!$current) {
            $resolveByHash->execute([(int)$merge['root_id'], (string)$row['source_file_hash']]);
            $current = $resolveByHash->fetch() ?: null;
        }
        $title = '';
        if ($current) {
            $titleStmt->execute([(string)$current['file_hash']]);
            $title = trim((string)($titleStmt->fetchColumn() ?: ''));
        }
        $sources[] = [
            'order' => (int)$row['source_order'],
            'name' => $title !== '' ? $title : ($current['file_name'] ?? $row['source_file_name']),
            'file_name' => $current['file_name'] ?? $row['source_file_name'],
            'relative_path' => $current['relative_path'] ?? $row['source_relative_path'],
            'available' => (bool)$current,
            'token' => $current ? base64url_encode((string)$current['file_path']) : null,
        ];
    }

    return [
        'is_merge' => true,
        'merge_id' => (int)$merge['id'],
        'created_at' => $merge['created_at'],
        'sources' => $sources,
    ];
}
