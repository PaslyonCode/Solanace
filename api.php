<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library_identity.php';
require_once __DIR__ . '/library_categories.php';
require_once __DIR__ . '/screenshot_worker_lib.php';
require_once __DIR__ . '/file_tools_lib.php';

auth_require_json();

// Buffer accidental warnings/notices so API responses always remain valid JSON.
ob_start();

ensure_cache_schema();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    match ($action) {
        'tree' => action_tree(),
        'refresh_cache' => action_refresh_cache(),
        'delete_cache' => action_delete_cache(),
        'create_folder' => action_create_folder(),
        'move_items' => action_move_items(),
        'set_items_category' => action_set_items_category(),
        'delete_items' => action_delete_items(),
        'card' => action_card(),
        'save_card' => action_save_card(),
        'categories' => action_categories(),
        'add_category' => action_add_category(),
        'upload_image' => action_upload_image(),
        'delete_image' => action_delete_image(),
        'delete_card' => action_delete_card(),
        'delete_file_from_card' => action_delete_file_from_card(),
        'set_video_thumbnail' => action_set_video_thumbnail(),
        'set_video_pinned' => action_set_video_pinned(),
        'start_screenshot_worker' => action_start_screenshot_worker(),
        'stop_screenshot_worker' => action_stop_screenshot_worker(),
        'screenshot_worker_status' => action_screenshot_worker_status(),
        'screenshot_jobs' => action_screenshot_jobs(),
        'begin_screenshot_job' => action_begin_screenshot_job(),
        'upload_video_screenshot' => action_upload_video_screenshot(),
        'finish_screenshot_job' => action_finish_screenshot_job(),
        'auth_settings' => action_auth_settings(),
        'update_auth' => action_update_auth(),
        'search' => action_search(),
        default => json_response(['ok' => false, 'error' => 'Неизвестное действие'], 400),
    };
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

function ensure_cache_schema(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_roots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_path TEXT NOT NULL,
            root_key CHAR(40) NOT NULL UNIQUE,
            library_uid CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_refresh_at DATETIME NULL,
            INDEX idx_last_refresh_at (last_refresh_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_files (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            relative_path TEXT NOT NULL,
            file_path TEXT NOT NULL,
            path_key CHAR(40) NOT NULL,
            file_name VARCHAR(1024) NOT NULL,
            file_hash CHAR(40) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            last_scan_token CHAR(32) NOT NULL DEFAULT '',
            first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_library_file_path (root_id, path_key),
            INDEX idx_library_files_root (root_id),
            INDEX idx_library_files_hash (file_hash),
            INDEX idx_library_files_scan (root_id, last_scan_token),
            CONSTRAINT fk_library_files_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_dirs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            relative_path TEXT NOT NULL,
            dir_path TEXT NOT NULL,
            path_key CHAR(40) NOT NULL,
            dir_name VARCHAR(1024) NOT NULL,
            last_scan_token CHAR(32) NOT NULL DEFAULT '',
            first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_library_dir_path (root_id, path_key),
            INDEX idx_library_dirs_root (root_id),
            INDEX idx_library_dirs_scan (root_id, last_scan_token),
            CONSTRAINT fk_library_dirs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );


    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS root_video_screenshot_sets (
            root_id INT UNSIGNED NOT NULL,
            file_hash CHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expected_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
            source_file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            thumbnail_sort_order TINYINT UNSIGNED NULL,
            duration_seconds DECIMAL(12,3) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (root_id, file_hash),
            INDEX idx_root_video_screenshot_sets_status (root_id, status),
            CONSTRAINT fk_root_video_screenshot_sets_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS root_video_screenshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            file_hash CHAR(40) NOT NULL,
            relative_path VARCHAR(1400) NOT NULL,
            position_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
            sort_order TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_root_video_screenshot_frame (root_id, file_hash, sort_order),
            INDEX idx_root_video_screenshots_hash (root_id, file_hash),
            CONSTRAINT fk_root_video_screenshots_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $thumbColumn = $pdo->query("SHOW COLUMNS FROM root_video_screenshot_sets LIKE 'thumbnail_sort_order'")->fetch();
    if (!$thumbColumn) {
        $pdo->exec("ALTER TABLE root_video_screenshot_sets ADD COLUMN thumbnail_sort_order TINYINT UNSIGNED NULL AFTER last_error");
    }

    $pinnedColumn = $pdo->query("SHOW COLUMNS FROM library_files LIKE 'is_pinned'")->fetch();
    if (!$pinnedColumn) {
        $pdo->exec("ALTER TABLE library_files ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER file_mtime");
    }

    $durationColumn = $pdo->query("SHOW COLUMNS FROM root_video_screenshot_sets LIKE 'duration_seconds'")->fetch();
    if (!$durationColumn) {
        $pdo->exec("ALTER TABLE root_video_screenshot_sets ADD COLUMN duration_seconds DECIMAL(12,3) NULL AFTER thumbnail_sort_order");
        // Existing generated sets predate duration_seconds. Recover duration from the
        // evenly distributed frame timestamps (frame N is duration*(N+1)/(count+1)).
        $pdo->exec(
            "UPDATE root_video_screenshot_sets rvss
             INNER JOIN (
                 SELECT r.root_id, r.file_hash,
                        MAX(r.position_seconds * (s.expected_count + 1) / (r.sort_order + 1)) AS duration_calc
                 FROM root_video_screenshots r
                 INNER JOIN root_video_screenshot_sets s
                   ON s.root_id = r.root_id AND s.file_hash = r.file_hash
                 WHERE r.position_seconds > 0
                 GROUP BY r.root_id, r.file_hash
             ) d ON d.root_id = rvss.root_id AND d.file_hash = rvss.file_hash
             SET rvss.duration_seconds = d.duration_calc
             WHERE rvss.duration_seconds IS NULL OR rvss.duration_seconds <= 0"
        );
    }

    li_ensure_schema();
    lc_ensure_schema();
    sw_ensure_schema();
    ft_ensure_schema();
    $done = true;
}

function video_extensions_regex(): string
{
    return '/\.(' . implode('|', array_map('preg_quote', VIDEO_EXTENSIONS)) . ')$/i';
}

function is_video_file(string $path): bool
{
    return is_file($path) && (bool)preg_match(video_extensions_regex(), $path);
}

function trim_path_separators(string $path): string
{
    $path = trim($path);
    if (preg_match('/^[A-Za-z]:[\\\\\/]*$/', $path)) {
        return strtoupper($path[0]) . ':\\';
    }
    return rtrim($path, "\\/");
}

function path_key(string $path): string
{
    $normalized = trim_path_separators(normalize_path($path));
    $canonical = str_replace('/', '\\', $normalized);
    if (DIRECTORY_SEPARATOR === '\\') {
        $canonical = mb_strtolower($canonical, 'UTF-8');
    }
    return sha1($canonical);
}

function root_key(string $path): string
{
    return li_path_key($path);
}

function path_basename(string $path): string
{
    $parts = preg_split('~[\\\\/]~', trim_path_separators($path));
    return $parts ? (string)end($parts) : $path;
}

function path_dirname(string $path): string
{
    $clean = trim_path_separators($path);
    $position = max((int)strrpos($clean, '/'), (int)strrpos($clean, '\\'));
    if ($position <= 0) return dirname($clean);
    return substr($clean, 0, $position);
}

function join_path(string $parent, string $name): string
{
    return trim_path_separators($parent) . DIRECTORY_SEPARATOR . ltrim($name, "\\/");
}

function comparable_path(string $path): string
{
    $value = str_replace('\\', '/', trim_path_separators(normalize_path($path)));
    return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($value, 'UTF-8') : $value;
}

function paths_equal(string $a, string $b): bool
{
    return comparable_path($a) === comparable_path($b);
}

function path_is_same_or_child(string $path, string $parent): bool
{
    $pathValue = comparable_path($path);
    $parentValue = rtrim(comparable_path($parent), '/');
    return $pathValue === $parentValue || str_starts_with($pathValue, $parentValue . '/');
}

function assert_path_inside_root(string $rootPath, string $path, bool $allowRoot = false): void
{
    if (!path_is_same_or_child($path, $rootPath)) {
        throw new RuntimeException('Операция разрешена только внутри выбранной корневой папки.');
    }
    if (!$allowRoot && paths_equal($path, $rootPath)) {
        throw new RuntimeException('Корневую папку нельзя перемещать или удалять.');
    }
}

function relative_path_from_root(string $root, string $path): string
{
    $rootNormalized = str_replace('\\', '/', trim_path_separators(normalize_path($root)));
    $pathNormalized = str_replace('\\', '/', normalize_path($path));
    $prefix = rtrim($rootNormalized, '/') . '/';

    $comparePath = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($pathNormalized, 'UTF-8') : $pathNormalized;
    $comparePrefix = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($prefix, 'UTF-8') : $prefix;

    if (str_starts_with($comparePath, $comparePrefix)) {
        return ltrim(substr($pathNormalized, strlen($prefix)), '/');
    }
    return path_basename($path);
}

function replace_path_prefix(string $path, string $oldPrefix, string $newPrefix): string
{
    $pathNormalized = str_replace('\\', '/', normalize_path($path));
    $oldNormalized = rtrim(str_replace('\\', '/', trim_path_separators(normalize_path($oldPrefix))), '/');
    $newNormalized = rtrim(str_replace('\\', '/', trim_path_separators(normalize_path($newPrefix))), '/');

    $comparePath = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($pathNormalized, 'UTF-8') : $pathNormalized;
    $compareOld = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($oldNormalized, 'UTF-8') : $oldNormalized;
    if ($comparePath !== $compareOld && !str_starts_with($comparePath, $compareOld . '/')) {
        throw new RuntimeException('Не удалось пересчитать путь внутри перемещаемой папки.');
    }

    $suffix = substr($pathNormalized, strlen($oldNormalized));
    return str_replace('/', DIRECTORY_SEPARATOR, $newNormalized . $suffix);
}

function validate_folder_name(string $name): string
{
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        throw new RuntimeException('Введите корректное имя папки.');
    }
    if (preg_match('/[<>:"\\\/|?*\x00-\x1F]/u', $name)) {
        throw new RuntimeException('Имя папки содержит недопустимые символы.');
    }
    if (preg_match('/[\. ]$/u', $name)) {
        throw new RuntimeException('Имя папки не должно заканчиваться точкой или пробелом.');
    }
    $reserved = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
    if (in_array(mb_strtoupper($name, 'UTF-8'), $reserved, true)) {
        throw new RuntimeException('Это имя зарезервировано Windows.');
    }
    if ($name === VIDEO_SCREENSHOT_DIRNAME) {
        throw new RuntimeException('Это имя зарезервировано для служебной папки кадров.');
    }
    return $name;
}

function file_hash(string $path): string
{
    $path = normalize_path($path);
    if (!is_file($path)) return sha1('missing|' . path_key($path));

    $chunkSize = 1024 * 1024;
    $size = filesize($path) ?: 0;
    $ctx = hash_init('sha1');
    hash_update($ctx, 'video-file-v2|' . $size . '|');

    $fp = @fopen($path, 'rb');
    if (!$fp) return sha1('unreadable|' . path_key($path) . '|' . $size);

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

function get_root_by_path(string $requestedPath, bool $createIfMissing = true): array
{
    return li_resolve_root($requestedPath, $createIfMissing);
}

function find_cached_file_by_path(string $path): ?array
{
    $stmt = db()->prepare('SELECT lf.*, lr.root_path FROM library_files lf INNER JOIN library_roots lr ON lr.id = lf.root_id WHERE lf.path_key = ? ORDER BY CHAR_LENGTH(lr.root_path) DESC LIMIT 1');
    $stmt->execute([path_key($path)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_card_by_hash(string $hash): ?array
{
    $stmt = db()->prepare('SELECT * FROM file_cards WHERE file_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_card_by_path(string $path): ?array
{
    $stmt = db()->prepare('SELECT * FROM file_cards WHERE file_path = ? LIMIT 1');
    $stmt->execute([normalize_path($path)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_or_create_card_for_cached_file(array $cachedFile): array
{
    $pdo = db();
    $card = find_card_by_hash($cachedFile['file_hash']);
    if (!$card) {
        $stmt = $pdo->prepare('INSERT INTO file_cards (file_path, file_hash) VALUES (?, ?)');
        $stmt->execute([$cachedFile['file_path'], $cachedFile['file_hash']]);
        $stmt = $pdo->prepare('SELECT * FROM file_cards WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        return $stmt->fetch();
    }

    if ($card['file_path'] !== $cachedFile['file_path']) {
        $stmt = $pdo->prepare('UPDATE file_cards SET file_path = ? WHERE id = ?');
        $stmt->execute([$cachedFile['file_path'], $card['id']]);
        $card['file_path'] = $cachedFile['file_path'];
    }
    return $card;
}

function get_or_create_card_by_path(string $path): array
{
    $cachedFile = find_cached_file_by_path($path);
    if (!$cachedFile) {
        throw new RuntimeException('Файл отсутствует в кэше. Нажмите «Обновить кэш».');
    }
    return get_or_create_card_for_cached_file($cachedFile);
}

function update_card_location_if_exists(string $hash, string $path): void
{
    $stmt = db()->prepare('UPDATE file_cards SET file_path = ? WHERE file_hash = ? AND file_path <> ?');
    $stmt->execute([$path, $hash, $path]);
}

function card_payload(array $card): array
{
    $stmt = db()->prepare('SELECT id, filename, original_name, created_at FROM file_images WHERE card_id = ? ORDER BY id DESC');
    $stmt->execute([$card['id']]);
    $images = array_map(function (array $image): array {
        $image['url'] = 'image.php?id=' . (int)$image['id'];
        return $image;
    }, $stmt->fetchAll());

    $cachedFile = find_cached_file_by_path($card['file_path']);
    $screenshots = [];
    if ($cachedFile) {
        $screenshotStmt = db()->prepare(
            "SELECT rvs.id, rvs.position_seconds, rvs.sort_order, rvs.created_at,
                    COALESCE(rvss.thumbnail_sort_order, 1) AS thumbnail_sort_order
             FROM root_video_screenshots rvs
             INNER JOIN root_video_screenshot_sets rvss
                ON rvss.root_id = rvs.root_id
               AND rvss.file_hash = rvs.file_hash
               AND rvss.status = 'ready'
             WHERE rvs.root_id = ? AND rvs.file_hash = ?
             ORDER BY rvs.sort_order"
        );
        $screenshotStmt->execute([(int)$cachedFile['root_id'], $card['file_hash']]);
        $screenshots = array_map(function (array $image): array {
            $image['position_seconds'] = (float)$image['position_seconds'];
            $image['sort_order'] = (int)$image['sort_order'];
            $image['thumbnail_sort_order'] = (int)$image['thumbnail_sort_order'];
            $image['is_thumbnail'] = $image['sort_order'] === $image['thumbnail_sort_order'];
            $image['url'] = 'screenshot.php?id=' . (int)$image['id'];
            return $image;
        }, $screenshotStmt->fetchAll());
    }

    return [
        'id' => (int)$card['id'],
        'file_path' => $card['file_path'],
        'file_name' => path_basename($card['file_path']),
        'token' => base64url_encode($card['file_path']),
        'custom_title' => $card['custom_title'] ?? '',
        'note' => $card['note'] ?? '',
        'category_id' => $cachedFile ? lc_category_id_for_file((int)$cachedFile['id']) : null,
        'is_pinned' => $cachedFile ? (bool)($cachedFile['is_pinned'] ?? false) : false,
        'duration_seconds' => $cachedFile ? video_duration_for_cached_file((int)$cachedFile['root_id'], (string)$cachedFile['file_hash']) : null,
        'file_size' => $cachedFile ? (int)($cachedFile['file_size'] ?? 0) : 0,
        'first_seen_at' => $cachedFile ? ($cachedFile['first_seen_at'] ?? null) : null,
        'screenshots' => $screenshots,
        'images' => $images,
    ];
}


function video_duration_for_cached_file(int $rootId, string $fileHash): ?float
{
    if ($rootId <= 0 || $fileHash === '') return null;
    $stmt = db()->prepare(
        'SELECT duration_seconds FROM root_video_screenshot_sets WHERE root_id = ? AND file_hash = ? LIMIT 1'
    );
    $stmt->execute([$rootId, $fileHash]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) return null;
    $duration = (float)$value;
    return $duration > 0 ? $duration : null;
}

function validate_file_hash_value(string $hash): string
{
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
        throw new RuntimeException('Некорректный хэш видеофайла.');
    }
    return $hash;
}

function get_root_by_id(int $rootId): array
{
    if ($rootId <= 0) throw new RuntimeException('Корневая папка не указана.');
    $stmt = db()->prepare('SELECT * FROM library_roots WHERE id = ? LIMIT 1');
    $stmt->execute([$rootId]);
    $root = $stmt->fetch();
    if (!$root) throw new RuntimeException('Корневая папка отсутствует в кэше.');
    return $root;
}

function video_screenshot_root_dir(array $root): string
{
    return join_path($root['root_path'], VIDEO_SCREENSHOT_DIRNAME);
}

function video_screenshot_hash_dir(array $root, string $hash): string
{
    return join_path(video_screenshot_root_dir($root), validate_file_hash_value($hash));
}

function ensure_root_video_screenshot_dir(array $root, ?string $hash = null): string
{
    $directory = $hash === null
        ? video_screenshot_root_dir($root)
        : video_screenshot_hash_dir($root, $hash);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать служебную папку для кадров: ' . $directory);
    }
    return $directory;
}

function screenshot_relative_path(string $hash, int $order): string
{
    return validate_file_hash_value($hash) . '/frame_' . str_pad((string)($order + 1), 2, '0', STR_PAD_LEFT) . '.jpg';
}

function screenshot_absolute_path(array $root, string $relativePath): string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('~(^|/)\.\.(/|$)~', $relativePath)) {
        throw new RuntimeException('Некорректный путь к кадру.');
    }
    return join_path(video_screenshot_root_dir($root), str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
}

function root_screenshot_files(int $rootId, string $hash): array
{
    $stmt = db()->prepare('SELECT id, relative_path FROM root_video_screenshots WHERE root_id = ? AND file_hash = ?');
    $stmt->execute([$rootId, $hash]);
    return $stmt->fetchAll();
}

function count_valid_root_video_screenshots(int $rootId, string $hash): int
{
    $root = get_root_by_id($rootId);
    $valid = 0;
    $missingIds = [];

    foreach (root_screenshot_files($rootId, $hash) as $row) {
        $path = screenshot_absolute_path($root, $row['relative_path']);
        if (is_file($path)) $valid++;
        else $missingIds[] = (int)$row['id'];
    }

    if ($missingIds) {
        foreach (array_chunk($missingIds, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            db()->prepare("DELETE FROM root_video_screenshots WHERE id IN ($placeholders)")->execute($chunk);
        }
    }
    return $valid;
}

function clear_root_video_screenshots(int $rootId, string $hash, bool $deleteSet = false): void
{
    $root = get_root_by_id($rootId);
    foreach (root_screenshot_files($rootId, $hash) as $row) {
        $path = screenshot_absolute_path($root, $row['relative_path']);
        if (is_file($path)) @unlink($path);
    }

    db()->prepare('DELETE FROM root_video_screenshots WHERE root_id = ? AND file_hash = ?')->execute([$rootId, $hash]);
    if ($deleteSet) {
        db()->prepare('DELETE FROM root_video_screenshot_sets WHERE root_id = ? AND file_hash = ?')->execute([$rootId, $hash]);
    }

    $hashDir = video_screenshot_hash_dir($root, $hash);
    // Если исходного видео больше нет, удаляем весь служебный каталог хэша:
    // вместе с кадрами, WAV, вырезанными фрагментами и MP4-копией.
    if ($deleteSet && is_dir($hashDir)) {
        remove_path_recursive($hashDir);
    } elseif (is_dir($hashDir)) {
        @rmdir($hashDir);
    }
    $rootDir = video_screenshot_root_dir($root);
    if (is_dir($rootDir)) @rmdir($rootDir);
}

function cleanup_orphan_root_video_screenshots(): void
{
    $stmt = db()->query(
        'SELECT rvss.root_id, rvss.file_hash
         FROM root_video_screenshot_sets rvss
         LEFT JOIN library_files lf
           ON lf.root_id = rvss.root_id
          AND lf.file_hash = rvss.file_hash
         WHERE lf.id IS NULL'
    );
    foreach ($stmt->fetchAll() as $row) {
        clear_root_video_screenshots((int)$row['root_id'], $row['file_hash'], true);
    }
}

function prepare_video_screenshot_sets_for_root(int $rootId): int
{
    $root = get_root_by_id($rootId);
    ensure_root_video_screenshot_dir($root);

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT file_hash, MAX(file_size) AS file_size, MAX(file_mtime) AS file_mtime
         FROM library_files WHERE root_id = ? GROUP BY file_hash'
    );
    $stmt->execute([$rootId]);
    $files = $stmt->fetchAll();

    $existingStmt = $pdo->prepare(
        'SELECT file_hash, status, last_error FROM root_video_screenshot_sets WHERE root_id = ?'
    );
    $existingStmt->execute([$rootId]);
    $existingSets = [];
    foreach ($existingStmt->fetchAll() as $row) $existingSets[$row['file_hash']] = $row;

    $upsert = $pdo->prepare(
        'INSERT INTO root_video_screenshot_sets
            (root_id, file_hash, status, expected_count, source_file_size, source_file_mtime, last_error)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            expected_count = VALUES(expected_count),
            source_file_size = VALUES(source_file_size),
            source_file_mtime = VALUES(source_file_mtime),
            last_error = VALUES(last_error)'
    );

    $pending = 0;
    foreach ($files as $file) {
        $hash = $file['file_hash'];
        $frameCount = count_valid_root_video_screenshots($rootId, $hash);
        if ($frameCount >= VIDEO_SCREENSHOT_COUNT) {
            $upsert->execute([
                $rootId, $hash, 'ready', VIDEO_SCREENSHOT_COUNT,
                (int)$file['file_size'], (int)$file['file_mtime'], null,
            ]);
            continue;
        }

        $wasPaused = (($existingSets[$hash]['status'] ?? '') === 'paused');
        $status = $wasPaused ? 'paused' : 'pending';
        $lastError = $wasPaused ? ($existingSets[$hash]['last_error'] ?? 'Очередь остановлена пользователем.') : null;
        $upsert->execute([
            $rootId, $hash, $status, VIDEO_SCREENSHOT_COUNT,
            (int)$file['file_size'], (int)$file['file_mtime'], $lastError,
        ]);
        if ($status === 'pending') $pending++;
    }

    cleanup_orphan_root_video_screenshots();
    return $pending;
}

function screenshot_job_payload(array $row): array
{
    return [
        'root_id' => (int)$row['root_id'],
        'file_hash' => $row['file_hash'],
        'file_path' => $row['file_path'],
        'file_name' => $row['file_name'],
        'token' => base64url_encode($row['file_path']),
        'expected_count' => (int)($row['expected_count'] ?? VIDEO_SCREENSHOT_COUNT),
    ];
}

function action_start_screenshot_worker(): void
{
    $root = get_root_by_path($_POST['root'] ?? $_GET['root'] ?? '', false);
    try {
        $worker = sw_launch_worker((int)$root['id'], true);
        json_response(['ok' => true, 'worker' => $worker]);
    } catch (Throwable $e) {
        sw_update_state((int)$root['id'], [
            'status' => 'error',
            'message' => mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        json_response(['ok' => false, 'error' => $e->getMessage(), 'worker' => sw_get_state((int)$root['id'])], 422);
    }
}

function action_stop_screenshot_worker(): void
{
    $root = get_root_by_path($_POST['root'] ?? $_GET['root'] ?? '', false);
    $rootId = (int)$root['id'];
    $state = sw_get_state($rootId);

    if (in_array($state['status'], ['queued', 'running', 'stopping'], true)) {
        sw_update_state($rootId, [
            'status' => 'stopping',
            'total_jobs' => (int)$state['total_jobs'],
            'completed_jobs' => (int)$state['completed_jobs'],
            'failed_jobs' => (int)$state['failed_jobs'],
            'current_file_name' => $state['current_file_name'],
            'current_frame' => (int)$state['current_frame'],
            'current_frame_total' => (int)$state['current_frame_total'],
            'message' => 'Останавливается текущий FFmpeg и ставится на паузу вся очередь.',
            'started_at' => $state['started_at'],
            'finished_at' => null,
        ]);
    } else {
        sw_pause_screenshot_jobs($rootId, 'Создание кадров остановлено пользователем.');
        sw_update_state($rootId, [
            'status' => 'paused',
            'total_jobs' => (int)$state['total_jobs'],
            'completed_jobs' => (int)$state['completed_jobs'],
            'failed_jobs' => (int)$state['failed_jobs'],
            'current_file_name' => null,
            'current_frame' => 0,
            'current_frame_total' => (int)$state['current_frame_total'],
            'message' => 'Создание кадров остановлено. Нажмите «Продолжить», чтобы возобновить очередь.',
            'started_at' => $state['started_at'],
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    json_response(['ok' => true, 'worker' => sw_get_state($rootId)]);
}

function action_screenshot_worker_status(): void
{
    $root = get_root_by_path($_GET['root'] ?? $_POST['root'] ?? '', false);
    json_response(['ok' => true, 'worker' => sw_get_state((int)$root['id'])]);
}

function action_screenshot_jobs(): void
{
    $root = get_root_by_path($_GET['root'] ?? $_POST['root'] ?? '', false);

    // Если вкладка была закрыта или генерация зависла, запись могла остаться
    // в состоянии processing. Через две минуты считаем такую задачу брошенной
    // и возвращаем ее в очередь.
    db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'pending', last_error = 'Предыдущая попытка была прервана и автоматически возвращена в очередь.'
         WHERE root_id = ?
           AND status = 'processing'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
    )->execute([(int)$root['id']]);
    $stmt = db()->prepare(
        "SELECT lf.root_id, lf.file_hash, MIN(lf.file_path) AS file_path,
                MIN(lf.file_name) AS file_name, rvss.expected_count
         FROM library_files lf
         INNER JOIN root_video_screenshot_sets rvss
            ON rvss.root_id = lf.root_id
           AND rvss.file_hash = lf.file_hash
         WHERE lf.root_id = ? AND rvss.status = 'pending'
         GROUP BY lf.root_id, lf.file_hash, rvss.expected_count
         ORDER BY MIN(lf.relative_path)"
    );
    $stmt->execute([(int)$root['id']]);
    $jobs = array_map('screenshot_job_payload', $stmt->fetchAll());
    json_response(['ok' => true, 'jobs' => $jobs]);
}

function action_begin_screenshot_job(): void
{
    $rootId = (int)($_POST['root_id'] ?? 0);
    $root = get_root_by_id($rootId);
    $hash = validate_file_hash_value($_POST['file_hash'] ?? '');

    $stmt = db()->prepare(
        'SELECT root_id, file_path, file_name, file_size, file_mtime
         FROM library_files
         WHERE root_id = ? AND file_hash = ?
         ORDER BY id LIMIT 1'
    );
    $stmt->execute([$rootId, $hash]);
    $file = $stmt->fetch();
    if (!$file || !is_file($file['file_path'])) {
        throw new RuntimeException('Видеофайл для создания кадров не найден в выбранной корневой папке.');
    }

    ensure_root_video_screenshot_dir($root, $hash);
    clear_root_video_screenshots($rootId, $hash, false);

    db()->prepare(
        "INSERT INTO root_video_screenshot_sets
            (root_id, file_hash, status, expected_count, source_file_size, source_file_mtime, last_error)
         VALUES (?, ?, 'processing', ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            status = 'processing',
            expected_count = VALUES(expected_count),
            source_file_size = VALUES(source_file_size),
            source_file_mtime = VALUES(source_file_mtime),
            last_error = NULL"
    )->execute([
        $rootId, $hash, VIDEO_SCREENSHOT_COUNT,
        (int)$file['file_size'], (int)$file['file_mtime'],
    ]);

    json_response(['ok' => true, 'job' => screenshot_job_payload([
        'root_id' => $rootId,
        'file_hash' => $hash,
        'file_path' => $file['file_path'],
        'file_name' => $file['file_name'],
        'expected_count' => VIDEO_SCREENSHOT_COUNT,
    ])]);
}

function action_upload_video_screenshot(): void
{
    $rootId = (int)($_POST['root_id'] ?? 0);
    $root = get_root_by_id($rootId);
    $hash = validate_file_hash_value($_POST['file_hash'] ?? '');
    $order = (int)($_POST['sort_order'] ?? -1);
    $position = (float)($_POST['position_seconds'] ?? 0);

    if ($order < 0 || $order >= VIDEO_SCREENSHOT_COUNT) {
        throw new RuntimeException('Некорректный номер кадра.');
    }
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('JPEG-кадр не был загружен.');
    }

    $exists = db()->prepare('SELECT 1 FROM library_files WHERE root_id = ? AND file_hash = ? LIMIT 1');
    $exists->execute([$rootId, $hash]);
    if (!$exists->fetchColumn()) {
        throw new RuntimeException('Видео больше не присутствует в выбранной корневой папке.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['image']['tmp_name']);
    if ($mime !== 'image/jpeg') throw new RuntimeException('Кадр должен быть передан в формате JPEG.');

    ensure_root_video_screenshot_dir($root, $hash);
    $relativePath = screenshot_relative_path($hash, $order);
    $destination = screenshot_absolute_path($root, $relativePath);
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
        throw new RuntimeException('Не удалось сохранить кадр в служебную подпапку корневого каталога.');
    }

    db()->prepare(
        'INSERT INTO root_video_screenshots
            (root_id, file_hash, relative_path, position_seconds, sort_order)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            relative_path = VALUES(relative_path),
            position_seconds = VALUES(position_seconds),
            created_at = CURRENT_TIMESTAMP'
    )->execute([$rootId, $hash, $relativePath, $position, $order]);

    json_response(['ok' => true]);
}

function action_finish_screenshot_job(): void
{
    $rootId = (int)($_POST['root_id'] ?? 0);
    get_root_by_id($rootId);
    $hash = validate_file_hash_value($_POST['file_hash'] ?? '');
    $success = ($_POST['success'] ?? '0') === '1';
    $error = trim($_POST['error'] ?? '');

    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM root_video_screenshots WHERE root_id = ? AND file_hash = ?'
    );
    $countStmt->execute([$rootId, $hash]);
    $count = (int)$countStmt->fetchColumn();

    if ($success && $count >= VIDEO_SCREENSHOT_COUNT) {
        db()->prepare(
            "UPDATE root_video_screenshot_sets
             SET status = 'ready', last_error = NULL
             WHERE root_id = ? AND file_hash = ?"
        )->execute([$rootId, $hash]);
        json_response(['ok' => true, 'status' => 'ready', 'count' => $count]);
    }

    clear_root_video_screenshots($rootId, $hash, false);
    $message = $error !== '' ? mb_substr($error, 0, 2000) : 'Не удалось создать полный набор кадров.';
    db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'error', last_error = ?
         WHERE root_id = ? AND file_hash = ?"
    )->execute([$message, $rootId, $hash]);
    json_response(['ok' => true, 'status' => 'error', 'count' => 0]);
}

function action_set_video_thumbnail(): void
{
    $screenshotId = (int)($_POST['screenshot_id'] ?? 0);
    if ($screenshotId <= 0) throw new RuntimeException('Не указан кадр для миниатюры.');

    $stmt = db()->prepare(
        "SELECT rvs.root_id, rvs.file_hash, rvs.sort_order
         FROM root_video_screenshots rvs
         INNER JOIN root_video_screenshot_sets rvss
            ON rvss.root_id = rvs.root_id
           AND rvss.file_hash = rvs.file_hash
         WHERE rvs.id = ?
         LIMIT 1"
    );
    $stmt->execute([$screenshotId]);
    $shot = $stmt->fetch();
    if (!$shot) throw new RuntimeException('Кадр не найден. Возможно, кадры были пересозданы.');

    $order = (int)$shot['sort_order'];
    if ($order < 0 || $order >= VIDEO_SCREENSHOT_COUNT) {
        throw new RuntimeException('Некорректный номер кадра.');
    }

    db()->prepare(
        'UPDATE root_video_screenshot_sets
         SET thumbnail_sort_order = ?, updated_at = CURRENT_TIMESTAMP
         WHERE root_id = ? AND file_hash = ?'
    )->execute([$order, (int)$shot['root_id'], (string)$shot['file_hash']]);

    json_response([
        'ok' => true,
        'screenshot_id' => $screenshotId,
        'sort_order' => $order,
        'thumbnail_url' => 'screenshot.php?id=' . $screenshotId,
    ]);
}

function action_set_video_pinned(): void
{
    $path = base64url_decode($_POST['token'] ?? '');
    if ($path === '') throw new RuntimeException('Файл не указан.');

    $cachedFile = find_cached_file_by_path($path);
    if (!$cachedFile) throw new RuntimeException('Файл отсутствует в кэше. Нажмите «Обновить кэш».');

    $pinnedRaw = strtolower(trim((string)($_POST['pinned'] ?? '1')));
    $pinned = in_array($pinnedRaw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    db()->prepare('UPDATE library_files SET is_pinned = ? WHERE id = ?')->execute([$pinned, (int)$cachedFile['id']]);

    json_response([
        'ok' => true,
        'pinned' => (bool)$pinned,
        'token' => base64url_encode((string)$cachedFile['file_path']),
    ]);
}

function action_categories(): void
{
    $rootPath = trim((string)($_GET['root'] ?? $_POST['root'] ?? ''));
    if ($rootPath === '') {
        json_response(['ok' => true, 'categories' => []]);
    }
    $root = get_root_by_path($rootPath, false);
    json_response(['ok' => true, 'categories' => lc_categories_for_root((int)$root['id'])]);
}

function action_add_category(): void
{
    $name = trim($_POST['name'] ?? '');
    if ($name === '') json_response(['ok' => false, 'error' => 'Введите название категории'], 422);
    $root = get_root_by_path((string)($_POST['root'] ?? ''), false);
    $rootId = (int)$root['id'];

    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO categories (root_id, name) VALUES (?, ?)')->execute([$rootId, $name]);
    $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE root_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$rootId, $name]);
    $category = $stmt->fetch();
    if (!$category) throw new RuntimeException('Не удалось создать категорию.');
    json_response(['ok' => true, 'category' => $category]);
}

function action_card(): void
{
    $path = base64url_decode($_GET['token'] ?? '');
    if ($path === '') json_response(['ok' => false, 'error' => 'Файл не указан'], 400);
    json_response(['ok' => true, 'card' => card_payload(get_or_create_card_by_path($path))]);
}

function action_save_card(): void
{
    $path = base64url_decode($_POST['token'] ?? '');
    if ($path === '') json_response(['ok' => false, 'error' => 'Файл не указан'], 400);

    $cachedFile = find_cached_file_by_path($path);
    if (!$cachedFile) throw new RuntimeException('Файл отсутствует в кэше. Нажмите «Обновить кэш».');
    $card = get_or_create_card_for_cached_file($cachedFile);
    $categoryId = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];
    db()->prepare('UPDATE file_cards SET custom_title = ?, note = ?, category_id = NULL WHERE id = ?')->execute([
        trim($_POST['custom_title'] ?? ''),
        trim($_POST['note'] ?? ''),
        $card['id'],
    ]);
    lc_set_file_category((int)$cachedFile['id'], (int)$cachedFile['root_id'], $categoryId);

    $stmt = db()->prepare('SELECT * FROM file_cards WHERE id = ?');
    $stmt->execute([$card['id']]);
    json_response(['ok' => true, 'card' => card_payload($stmt->fetch())]);
}

function action_upload_image(): void
{
    ensure_upload_dir();
    $path = base64url_decode($_POST['token'] ?? '');
    if ($path === '') json_response(['ok' => false, 'error' => 'Файл не указан'], 400);
    if (empty($_FILES['images'])) json_response(['ok' => false, 'error' => 'Файлы не загружены'], 400);

    $card = get_or_create_card_by_path($path);
    $files = reformat_files_array($_FILES['images']);
    if (count($files) > 20) throw new RuntimeException('За один раз можно загрузить не более 20 изображений.');
    $maxImageBytes = defined('MAX_IMAGE_UPLOAD_BYTES') ? (int)MAX_IMAGE_UPLOAD_BYTES : 20 * 1024 * 1024;
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $pdo = db();

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxImageBytes) continue;
        if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) continue;
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) continue;

        $filename = sha1($card['id'] . microtime(true) . random_int(1, PHP_INT_MAX)) . '.' . $allowed[$mime];
        if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename)) {
            $pdo->prepare('INSERT INTO file_images (card_id, filename, original_name) VALUES (?, ?, ?)')->execute([
                $card['id'], $filename, $file['name'] ?? null,
            ]);
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM file_cards WHERE id = ?');
    $stmt->execute([$card['id']]);
    json_response(['ok' => true, 'card' => card_payload($stmt->fetch())]);
}

function reformat_files_array(array $files): array
{
    if (!is_array($files['name'])) return [$files];
    $result = [];
    foreach ($files['name'] as $index => $name) {
        $result[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? null,
            'tmp_name' => $files['tmp_name'][$index] ?? null,
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $result;
}

function action_delete_image(): void
{
    $id = (int)($_POST['image_id'] ?? 0);
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Картинка не указана'], 400);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM file_images WHERE id = ?');
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    if ($image) {
        $file = UPLOAD_DIR . DIRECTORY_SEPARATOR . $image['filename'];
        if (is_file($file)) @unlink($file);
        $pdo->prepare('DELETE FROM file_images WHERE id = ?')->execute([$id]);
    }
    json_response(['ok' => true]);
}

function action_delete_card(): void
{
    $path = base64url_decode($_POST['token'] ?? '');
    if ($path === '') json_response(['ok' => false, 'error' => 'Файл не указан'], 400);

    $cachedFile = find_cached_file_by_path($path);
    $card = $cachedFile ? find_card_by_hash($cachedFile['file_hash']) : find_card_by_path($path);
    if (!$card) json_response(['ok' => true]);

    $stmt = db()->prepare('SELECT filename FROM file_images WHERE card_id = ?');
    $stmt->execute([$card['id']]);
    foreach ($stmt->fetchAll() as $image) {
        $file = UPLOAD_DIR . DIRECTORY_SEPARATOR . $image['filename'];
        if (is_file($file)) @unlink($file);
    }
    db()->prepare('DELETE FROM file_cards WHERE id = ?')->execute([$card['id']]);
    json_response(['ok' => true]);
}


function action_delete_file_from_card(): void
{
    $file = ft_find_cached_file_by_token((string)($_POST['token'] ?? ''));
    $fileId = (int)$file['id'];
    $rootId = (int)$file['root_id'];
    $rootPath = trim_path_separators(normalize_path((string)$file['root_path']));
    $sourcePath = normalize_path((string)$file['file_path']);
    $fileHash = strtolower((string)$file['file_hash']);

    assert_path_inside_root($rootPath, $sourcePath, false);
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Видеофайл уже отсутствует на диске. Обновите кэш библиотеки.');
    }

    $trash = join_path($rootPath, '.video_catalog_delete_' . bin2hex(random_bytes(8)));
    if (!@mkdir($trash, 0775, false)) throw new RuntimeException('Не удалось подготовить безопасное удаление.');

    $trashVideo = join_path($trash, path_basename($sourcePath));
    if (!@rename($sourcePath, $trashVideo)) {
        @rmdir($trash);
        throw new RuntimeException('Не удалось переместить видео во временную папку удаления.');
    }

    // The service directory is shared by identical hashes inside one library,
    // so remove it only when this is the last cached file with that hash here.
    $sameHashStmt = db()->prepare('SELECT COUNT(*) FROM library_files WHERE root_id = ? AND file_hash = ? AND id <> ?');
    $sameHashStmt->execute([$rootId, $fileHash, $fileId]);
    $keepServiceDir = ((int)$sameHashStmt->fetchColumn()) > 0;

    $serviceMoved = false;
    $serviceDir = '';
    $trashService = '';
    if (!$keepServiceDir) {
        try {
            $serviceDir = ft_hash_dir($file, $fileHash);
        } catch (Throwable $ignored) {
            $serviceDir = '';
        }
        if ($serviceDir !== '' && is_dir($serviceDir)) {
            $trashService = join_path($trash, 'service_' . $fileHash);
            if (!@rename($serviceDir, $trashService)) {
                @rename($trashVideo, $sourcePath);
                @rmdir($trash);
                throw new RuntimeException('Не удалось подготовить служебные файлы видео к удалению.');
            }
            $serviceMoved = true;
        }
    }

    $pdo = db();
    $imageFiles = [];
    $pdo->beginTransaction();
    try {
        // Deleting library_files cascades file-tool/screenshot/transcript records,
        // promoted-clip links and other per-file cache rows.
        $pdo->prepare('DELETE FROM library_files WHERE id = ?')->execute([$fileId]);

        $remainingStmt = $pdo->prepare('SELECT file_path FROM library_files WHERE file_hash = ? ORDER BY id LIMIT 1');
        $remainingStmt->execute([$fileHash]);
        $remainingPath = $remainingStmt->fetchColumn();

        if ($remainingPath) {
            update_card_location_if_exists($fileHash, (string)$remainingPath);
        } else {
            $cardStmt = $pdo->prepare('SELECT id FROM file_cards WHERE file_hash = ? LIMIT 1');
            $cardStmt->execute([$fileHash]);
            $cardId = $cardStmt->fetchColumn();
            if ($cardId) {
                $imageStmt = $pdo->prepare('SELECT filename FROM file_images WHERE card_id = ?');
                $imageStmt->execute([(int)$cardId]);
                foreach ($imageStmt->fetchAll() as $image) $imageFiles[] = (string)$image['filename'];
                $pdo->prepare('DELETE FROM file_cards WHERE id = ?')->execute([(int)$cardId]);
            }
        }

        $pdo->prepare('UPDATE library_roots SET last_refresh_at = NOW() WHERE id = ?')->execute([$rootId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($serviceMoved && $trashService !== '' && $serviceDir !== '') @rename($trashService, $serviceDir);
        @rename($trashVideo, $sourcePath);
        remove_path_recursive($trash);
        throw $e;
    }

    foreach ($imageFiles as $filename) {
        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) @unlink($path);
    }

    if (!remove_path_recursive($trash)) {
        throw new RuntimeException('Видео удалено из базы, но временную папку удаления не удалось полностью очистить: ' . $trash);
    }

    json_response(['ok' => true, 'deleted' => 1]);
}

function reset_and_count_video_screenshot_jobs(int $rootId): int
{
    // Не сбрасываем активную серверную задачу при каждом открытии дерева.
    // Возвращаем в очередь только явно зависшие обработки.
    db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'pending', last_error = 'Зависшая обработка автоматически возвращена в очередь.'
         WHERE root_id = ? AND status = 'processing'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
    )->execute([$rootId]);

    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM root_video_screenshot_sets
         WHERE root_id = ? AND status = 'pending'"
    );
    $stmt->execute([$rootId]);
    return (int)$stmt->fetchColumn();
}

function action_tree(): void
{
    $root = get_root_by_path($_GET['root'] ?? '');
    $relocatedFrom = $root['_relocated_from'] ?? null;
    $wasNew = empty($root['last_refresh_at']);
    $stats = null;
    if ($wasNew) {
        $stats = refresh_root_cache($root);
        $root = get_root_by_path($root['root_path'], false);
    }

    $screenshotJobsPending = reset_and_count_video_screenshot_jobs((int)$root['id']);
    $workerError = null;
    if ($screenshotJobsPending > 0) {
        try {
            sw_launch_worker((int)$root['id']);
        } catch (Throwable $e) {
            $workerError = $e->getMessage();
        }
    }
    $categoryId = ($_GET['category_id'] ?? '') === '' ? null : (int)$_GET['category_id'];
    json_response([
        'ok' => true,
        'root' => $root['root_path'],
        'relocated_from' => $relocatedFrom,
        'library_uid' => $root['library_uid'] ?? null,
        'tree' => build_cached_tree($root, $categoryId),
        'cache' => [
            'last_refresh_at' => $root['last_refresh_at'],
            'initialized_now' => $wasNew,
            'screenshots_pending' => $screenshotJobsPending,
            'screenshot_worker' => sw_get_state((int)$root['id']),
            'screenshot_worker_error' => $workerError,
            'stats' => $stats,
        ],
    ]);
}

function action_refresh_cache(): void
{
    $root = get_root_by_path($_POST['root'] ?? $_GET['root'] ?? '');
    $relocatedFrom = $root['_relocated_from'] ?? null;
    $stats = refresh_root_cache($root);
    $root = get_root_by_path($root['root_path'], false);
    $workerError = null;
    if ((int)($stats['screenshots_pending'] ?? 0) > 0) {
        try {
            // Явное обновление кэша считается командой продолжить очередь.
            // Это также восстанавливает старое зависшее состояние stopping.
            sw_launch_worker((int)$root['id'], true);
        } catch (Throwable $e) {
            $workerError = $e->getMessage();
        }
    }
    json_response([
        'ok' => true,
        'root' => $root['root_path'],
        'relocated_from' => $relocatedFrom,
        'library_uid' => $root['library_uid'] ?? null,
        'cache' => [
            'last_refresh_at' => $root['last_refresh_at'],
            'stats' => $stats,
            'screenshot_worker' => sw_get_state((int)$root['id']),
            'screenshot_worker_error' => $workerError,
        ],
    ]);
}

function delete_directory_tree(string $path): void
{
    if (!file_exists($path)) return;
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path) && file_exists($path)) throw new RuntimeException('Не удалось удалить файл: ' . $path);
        return;
    }
    $items = @scandir($path);
    if ($items === false) throw new RuntimeException('Не удалось прочитать служебную папку: ' . $path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        delete_directory_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    if (!@rmdir($path) && is_dir($path)) throw new RuntimeException('Не удалось удалить папку: ' . $path);
}

function action_delete_cache(): void
{
    $rootPath = trim((string)($_POST['root'] ?? $_GET['root'] ?? ''));
    if ($rootPath === '') throw new RuntimeException('Сначала выберите корневую папку.');
    $root = get_root_by_path($rootPath, false);
    $rootId = (int)$root['id'];

    // Do not remove database rows underneath a currently running FFmpeg job.
    $jobStmt = db()->prepare("SELECT COUNT(*) FROM file_tool_jobs WHERE root_id = ? AND status IN ('pending','running')");
    $jobStmt->execute([$rootId]);
    if ((int)$jobStmt->fetchColumn() > 0) {
        throw new RuntimeException('Для этой библиотеки еще выполняется операция FFmpeg. Дождитесь ее завершения и повторите удаление кэша.');
    }

    if (sw_worker_is_active($rootId)) {
        sw_force_terminate_worker($rootId);
        if (sw_worker_is_active($rootId)) {
            throw new RuntimeException('Не удалось остановить фоновое создание кадров. Сначала остановите worker и повторите удаление кэша.');
        }
    }

    $serviceDir = video_screenshot_root_dir($root);
    $physicalDeleted = false;
    if (is_dir($serviceDir)) {
        delete_directory_tree($serviceDir);
        $physicalDeleted = true;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Scoped categories and category assignments belong to this library.
        $pdo->prepare('DELETE FROM categories WHERE root_id = ?')->execute([$rootId]);
        // library_files, dirs, screenshots, derivatives, transcripts, jobs and
        // worker state cascade from library_roots/library_files.
        $pdo->prepare('DELETE FROM library_roots WHERE id = ?')->execute([$rootId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    @unlink(sw_worker_lock_path($rootId));
    json_response([
        'ok' => true,
        'root' => (string)$root['root_path'],
        'library_uid' => $root['library_uid'] ?? null,
        'physical_cache_deleted' => $physicalDeleted,
    ]);
}

function refresh_root_cache(array $root): array
{
    $rootPath = trim_path_separators(normalize_path($root['root_path']));
    if (!is_dir($rootPath)) throw new RuntimeException('Папка недоступна для обновления кэша: ' . $rootPath);

    $pdo = db();
    $rootId = (int)$root['id'];
    $scanToken = bin2hex(random_bytes(16));

    $fileStmt = $pdo->prepare('SELECT * FROM library_files WHERE root_id = ?');
    $fileStmt->execute([$rootId]);
    $cachedFiles = $fileStmt->fetchAll();
    $cachedFilesByPath = [];
    foreach ($cachedFiles as $row) $cachedFilesByPath[$row['path_key']] = $row;

    $dirStmt = $pdo->prepare('SELECT * FROM library_dirs WHERE root_id = ?');
    $dirStmt->execute([$rootId]);
    $cachedDirs = $dirStmt->fetchAll();
    $cachedDirsByPath = [];
    foreach ($cachedDirs as $row) $cachedDirsByPath[$row['path_key']] = $row;

    $observedFiles = [];
    $observedDirs = [];

    $directory = new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $current): bool {
        if (str_starts_with($current->getFilename(), '.video_catalog_delete_')) return false;
        if ($current->isDir() && $current->getFilename() === VIDEO_SCREENSHOT_DIRNAME) return false;
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $info) {
        $absolutePath = normalize_path($info->getPathname());
        if ($info->isDir()) {
            $key = path_key($absolutePath);
            $observedDirs[$key] = [
                'path_key' => $key,
                'dir_path' => $absolutePath,
                'relative_path' => relative_path_from_root($rootPath, $absolutePath),
                'dir_name' => path_basename($absolutePath),
                'old' => $cachedDirsByPath[$key] ?? null,
            ];
            continue;
        }
        if (!$info->isFile() || !is_video_file($absolutePath)) continue;

        $key = path_key($absolutePath);
        $size = (int)($info->getSize() ?: 0);
        $mtime = (int)($info->getMTime() ?: 0);
        $old = $cachedFilesByPath[$key] ?? null;
        $hash = $old && (int)$old['file_size'] === $size && (int)$old['file_mtime'] === $mtime
            ? $old['file_hash']
            : file_hash($absolutePath);

        $observedFiles[$key] = [
            'path_key' => $key,
            'file_path' => $absolutePath,
            'relative_path' => relative_path_from_root($rootPath, $absolutePath),
            'file_name' => path_basename($absolutePath),
            'file_hash' => $hash,
            'file_size' => $size,
            'file_mtime' => $mtime,
            'old' => $old,
        ];
    }

    $freeRowsByHash = [];
    foreach ($cachedFiles as $row) {
        if (!isset($observedFiles[$row['path_key']])) $freeRowsByHash[$row['file_hash']][] = $row;
    }

    $stats = [
        'added' => 0, 'changed' => 0, 'moved' => 0, 'removed' => 0, 'unchanged' => 0,
        'dirs_added' => 0, 'dirs_removed' => 0,
    ];
    $reusedIds = [];

    $insertFile = $pdo->prepare('INSERT INTO library_files (root_id, relative_path, file_path, path_key, file_name, file_hash, file_size, file_mtime, last_scan_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $updateFile = $pdo->prepare('UPDATE library_files SET relative_path = ?, file_path = ?, path_key = ?, file_name = ?, file_hash = ?, file_size = ?, file_mtime = ?, last_scan_token = ? WHERE id = ?');
    $markFile = $pdo->prepare('UPDATE library_files SET last_scan_token = ? WHERE id = ?');
    $deleteFiles = $pdo->prepare('DELETE FROM library_files WHERE root_id = ? AND last_scan_token <> ?');

    $insertDir = $pdo->prepare('INSERT INTO library_dirs (root_id, relative_path, dir_path, path_key, dir_name, last_scan_token) VALUES (?, ?, ?, ?, ?, ?)');
    $updateDir = $pdo->prepare('UPDATE library_dirs SET relative_path = ?, dir_path = ?, path_key = ?, dir_name = ?, last_scan_token = ? WHERE id = ?');
    $markDir = $pdo->prepare('UPDATE library_dirs SET last_scan_token = ? WHERE id = ?');
    $deleteDirs = $pdo->prepare('DELETE FROM library_dirs WHERE root_id = ? AND last_scan_token <> ?');

    $pdo->beginTransaction();
    try {
        foreach ($observedDirs as $entry) {
            if ($entry['old']) {
                $same = $entry['old']['dir_path'] === $entry['dir_path'] && $entry['old']['relative_path'] === $entry['relative_path'];
                if ($same) {
                    $markDir->execute([$scanToken, $entry['old']['id']]);
                } else {
                    $updateDir->execute([$entry['relative_path'], $entry['dir_path'], $entry['path_key'], $entry['dir_name'], $scanToken, $entry['old']['id']]);
                }
            } else {
                $insertDir->execute([$rootId, $entry['relative_path'], $entry['dir_path'], $entry['path_key'], $entry['dir_name'], $scanToken]);
                $stats['dirs_added']++;
            }
        }

        foreach ($observedFiles as $entry) {
            $old = $entry['old'];
            if ($old) {
                $same = $old['file_hash'] === $entry['file_hash']
                    && (int)$old['file_size'] === $entry['file_size']
                    && (int)$old['file_mtime'] === $entry['file_mtime']
                    && $old['file_path'] === $entry['file_path']
                    && $old['relative_path'] === $entry['relative_path'];
                if ($same) {
                    $markFile->execute([$scanToken, $old['id']]);
                    $stats['unchanged']++;
                } else {
                    $updateFile->execute([$entry['relative_path'], $entry['file_path'], $entry['path_key'], $entry['file_name'], $entry['file_hash'], $entry['file_size'], $entry['file_mtime'], $scanToken, $old['id']]);
                    $stats['changed']++;
                    update_card_location_if_exists($entry['file_hash'], $entry['file_path']);
                }
                continue;
            }

            $reused = null;
            if (!empty($freeRowsByHash[$entry['file_hash']])) {
                while ($freeRowsByHash[$entry['file_hash']]) {
                    $candidate = array_shift($freeRowsByHash[$entry['file_hash']]);
                    if (!isset($reusedIds[$candidate['id']])) {
                        $reused = $candidate;
                        break;
                    }
                }
            }

            if ($reused) {
                $reusedIds[$reused['id']] = true;
                $updateFile->execute([$entry['relative_path'], $entry['file_path'], $entry['path_key'], $entry['file_name'], $entry['file_hash'], $entry['file_size'], $entry['file_mtime'], $scanToken, $reused['id']]);
                $stats['moved']++;
                update_card_location_if_exists($entry['file_hash'], $entry['file_path']);
            } else {
                $insertFile->execute([$rootId, $entry['relative_path'], $entry['file_path'], $entry['path_key'], $entry['file_name'], $entry['file_hash'], $entry['file_size'], $entry['file_mtime'], $scanToken]);
                $stats['added']++;
                update_card_location_if_exists($entry['file_hash'], $entry['file_path']);
            }
        }

        $pdo->prepare('UPDATE library_roots SET root_path = ?, last_refresh_at = NOW() WHERE id = ?')->execute([$rootPath, $rootId]);
        $deleteFiles->execute([$rootId, $scanToken]);
        $stats['removed'] = $deleteFiles->rowCount();
        $deleteDirs->execute([$rootId, $scanToken]);
        $stats['dirs_removed'] = $deleteDirs->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stats['screenshots_pending'] = prepare_video_screenshot_sets_for_root($rootId);
    return $stats;
}

function ensure_tree_directory(array &$tree, string $relativePath, string $rootPath): void
{
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), 'strlen'));
    $node =& $tree;
    $currentPath = trim_path_separators($rootPath);
    foreach ($parts as $part) {
        $currentPath = join_path($currentPath, $part);
        if (!isset($node['_dirs'][$part])) {
            $node['_dirs'][$part] = [
                'type' => 'dir', 'name' => $part, 'path' => $currentPath,
                'children' => [], '_dirs' => [],
            ];
        }
        $node =& $node['_dirs'][$part];
    }
    unset($node);
}

function build_cached_tree(array $root, ?int $categoryId = null): array
{
    $rootPath = $root['root_path'];
    $tree = [
        'type' => 'dir',
        'name' => path_basename($rootPath) ?: $rootPath,
        'path' => $rootPath,
        'children' => [],
        '_dirs' => [],
    ];

    $dirStmt = db()->prepare('SELECT relative_path FROM library_dirs WHERE root_id = ? ORDER BY relative_path');
    $dirStmt->execute([(int)$root['id']]);
    foreach ($dirStmt->fetchAll() as $dir) ensure_tree_directory($tree, $dir['relative_path'], $rootPath);

    $sql = 'SELECT lf.file_path, lf.relative_path, lf.file_name, lf.file_hash, lf.is_pinned, fc.custom_title, lfc.category_id,
                   c.name AS category_name, rvs_thumb.id AS thumbnail_id, rvss_thumb.duration_seconds
            FROM library_files lf
            LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
            LEFT JOIN library_file_categories lfc ON lfc.library_file_id = lf.id
            LEFT JOIN categories c ON c.id = lfc.category_id AND c.root_id = lf.root_id
            LEFT JOIN root_video_screenshot_sets rvss_thumb
              ON rvss_thumb.root_id = lf.root_id
             AND rvss_thumb.file_hash = lf.file_hash
             AND rvss_thumb.status = \'ready\'
            LEFT JOIN root_video_screenshots rvs_thumb
              ON rvs_thumb.root_id = lf.root_id
             AND rvs_thumb.file_hash = lf.file_hash
             AND rvs_thumb.sort_order = COALESCE(rvss_thumb.thumbnail_sort_order, 1)
            WHERE lf.root_id = ?';
    $params = [(int)$root['id']];
    if ($categoryId && lc_category_belongs_to_root($categoryId, (int)$root['id'])) {
        $sql .= ' AND lfc.category_id = ?';
        $params[] = $categoryId;
    }
    $sql .= ' ORDER BY lf.relative_path';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $relativeDir = str_replace('\\', '/', path_dirname($row['relative_path']));
        if ($relativeDir === '.' || $relativeDir === DIRECTORY_SEPARATOR) $relativeDir = '';
        if ($relativeDir !== '') ensure_tree_directory($tree, $relativeDir, $rootPath);

        $parts = array_values(array_filter(explode('/', $relativeDir), 'strlen'));
        $node =& $tree;
        foreach ($parts as $part) $node =& $node['_dirs'][$part];
        $node['children'][] = [
            'type' => 'file',
            'name' => $row['file_name'],
            'path' => $row['file_path'],
            'token' => base64url_encode($row['file_path']),
            'title' => $row['custom_title'] ?: '',
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'] ?: '',
            'thumbnail_url' => !empty($row['thumbnail_id']) ? 'screenshot.php?id=' . (int)$row['thumbnail_id'] . '&thumb=1' : '',
            'duration_seconds' => isset($row['duration_seconds']) && (float)$row['duration_seconds'] > 0 ? (float)$row['duration_seconds'] : null,
            'is_pinned' => (bool)($row['is_pinned'] ?? false),
        ];
        unset($node);
    }

    return finalize_tree_node($tree);
}

function finalize_tree_node(array $node): array
{
    foreach ($node['_dirs'] ?? [] as $dir) $node['children'][] = finalize_tree_node($dir);
    unset($node['_dirs']);
    usort($node['children'], function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $node;
}

function action_auth_settings(): void
{
    json_response(['ok' => true, 'username' => auth_current_username()]);
}

function action_update_auth(): void
{
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newUsername = (string)($_POST['new_username'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($currentPassword === '') throw new RuntimeException('Введите текущий пароль.');
    if ($newPassword !== '' && $newPassword !== $confirm) {
        throw new RuntimeException('Новый пароль и подтверждение не совпадают.');
    }

    $result = auth_update_credentials($currentPassword, $newUsername, $newPassword);
    json_response(['ok' => true, 'auth' => $result]);
}

function action_search(): void
{
    $root = get_root_by_path($_GET['root'] ?? '', false);
    $query = trim($_GET['q'] ?? '');
    $categoryId = ($_GET['category_id'] ?? '') === '' ? null : (int)$_GET['category_id'];
    if ($categoryId && !lc_category_belongs_to_root($categoryId, (int)$root['id'])) $categoryId = null;
    if ($query === '' && !$categoryId) json_response(['ok' => true, 'results' => []]);

    $where = ['lf.root_id = ?'];
    $params = [(int)$root['id']];
    if ($query !== '') {
        $like = '%' . $query . '%';
        $where[] = '(lf.file_name LIKE ? OR lf.relative_path LIKE ? OR lf.file_path LIKE ? OR fc.custom_title LIKE ? OR fc.note LIKE ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM file_transcripts ft_search WHERE ft_search.library_file_id = lf.id AND ft_search.full_text LIKE ?))';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($categoryId && lc_category_belongs_to_root($categoryId, (int)$root['id'])) {
        $where[] = 'lfc.category_id = ?';
        $params[] = $categoryId;
    }

    $sql = 'SELECT lf.file_path, lf.file_name, lf.is_pinned, fc.custom_title, fc.note, c.name AS category_name, rvss.duration_seconds
            FROM library_files lf
            LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
            LEFT JOIN library_file_categories lfc ON lfc.library_file_id = lf.id
            LEFT JOIN categories c ON c.id = lfc.category_id AND c.root_id = lf.root_id
            LEFT JOIN root_video_screenshot_sets rvss ON rvss.root_id = lf.root_id AND rvss.file_hash = lf.file_hash
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(fc.updated_at, lf.last_seen_at) DESC, lf.relative_path
            LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $results = array_map(function (array $row): array {
        return [
            'file_path' => $row['file_path'],
            'file_name' => $row['file_name'],
            'token' => base64url_encode($row['file_path']),
            'custom_title' => $row['custom_title'] ?: '',
            'note' => mb_substr((string)($row['note'] ?? ''), 0, 240),
            'category_name' => $row['category_name'] ?: '',
            'duration_seconds' => isset($row['duration_seconds']) && (float)$row['duration_seconds'] > 0 ? (float)$row['duration_seconds'] : null,
            'is_pinned' => (bool)($row['is_pinned'] ?? false),
        ];
    }, $stmt->fetchAll());

    json_response(['ok' => true, 'results' => $results]);
}

function action_create_folder(): void
{
    $root = get_root_by_path($_POST['root'] ?? '', false);
    $rootPath = trim_path_separators(normalize_path($root['root_path']));
    $parentPath = trim_path_separators(normalize_path($_POST['parent_path'] ?? $rootPath));
    assert_path_inside_root($rootPath, $parentPath, true);
    if (!is_dir($parentPath)) throw new RuntimeException('Родительская папка не найдена.');

    $name = validate_folder_name($_POST['name'] ?? '');
    $newPath = join_path($parentPath, $name);
    if (file_exists($newPath)) throw new RuntimeException('Файл или папка с таким именем уже существует.');
    if (!@mkdir($newPath, 0775, false)) throw new RuntimeException('Не удалось создать папку. Проверьте права Apache/PHP.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rootStmt = $pdo->query('SELECT id, root_path FROM library_roots');
        $affectedRootIds = [];
        $insert = $pdo->prepare(
            'INSERT INTO library_dirs (root_id, relative_path, dir_path, path_key, dir_name, last_scan_token)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE relative_path = VALUES(relative_path), dir_path = VALUES(dir_path), dir_name = VALUES(dir_name), last_seen_at = CURRENT_TIMESTAMP'
        );
        foreach ($rootStmt->fetchAll() as $cachedRoot) {
            if (!path_is_same_or_child($newPath, $cachedRoot['root_path']) || paths_equal($newPath, $cachedRoot['root_path'])) continue;
            $insert->execute([
                (int)$cachedRoot['id'], relative_path_from_root($cachedRoot['root_path'], $newPath), normalize_path($newPath), path_key($newPath), $name, '',
            ]);
            $affectedRootIds[] = (int)$cachedRoot['id'];
        }
        touch_roots($affectedRootIds);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @rmdir($newPath);
        throw $e;
    }

    json_response(['ok' => true, 'path' => normalize_path($newPath)]);
}

function parse_items_payload(): array
{
    $decoded = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($decoded) || !$decoded) throw new RuntimeException('Не выбраны файлы или папки.');

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $type = ($item['type'] ?? '') === 'dir' ? 'dir' : 'file';
        $path = trim_path_separators(normalize_path((string)($item['path'] ?? '')));
        if ($path !== '') $items[] = ['type' => $type, 'path' => $path];
    }
    if (!$items) throw new RuntimeException('Не выбраны файлы или папки.');
    return $items;
}

function normalize_selected_items(array $items, string $rootPath): array
{
    $unique = [];
    foreach ($items as $item) {
        $path = trim_path_separators(normalize_path($item['path']));
        assert_path_inside_root($rootPath, $path, false);
        $actualType = is_dir($path) ? 'dir' : (is_file($path) ? 'file' : null);
        if (!$actualType) throw new RuntimeException('Объект не найден: ' . $path);
        $unique[$actualType . '|' . path_key($path)] = ['type' => $actualType, 'path' => $path];
    }

    $values = array_values($unique);
    usort($values, fn(array $a, array $b): int => strlen(comparable_path($a['path'])) <=> strlen(comparable_path($b['path'])));
    $result = [];
    foreach ($values as $item) {
        $covered = false;
        foreach ($result as $parent) {
            if ($parent['type'] === 'dir' && path_is_same_or_child($item['path'], $parent['path'])) {
                $covered = true;
                break;
            }
        }
        if (!$covered) $result[] = $item;
    }
    return $result;
}

function action_set_items_category(): void
{
    $root = get_root_by_path($_POST['root'] ?? '', false);
    $rootPath = trim_path_separators(normalize_path($root['root_path']));
    $items = normalize_selected_items(parse_items_payload(), $rootPath);

    foreach ($items as $item) {
        if ($item['type'] !== 'file') {
            throw new RuntimeException('Категорию можно назначить только видеофайлам.');
        }
    }

    $rawCategory = (string)($_POST['category_id'] ?? '');
    $categoryId = ($rawCategory === '' || $rawCategory === '__none__') ? null : (int)$rawCategory;
    $rootId = (int)$root['id'];
    if ($categoryId !== null && !lc_category_belongs_to_root($categoryId, $rootId)) {
        throw new RuntimeException('Выбранная категория относится к другой библиотеке.');
    }

    $find = db()->prepare('SELECT id FROM library_files WHERE root_id = ? AND path_key = ? LIMIT 1');
    $changed = 0;
    foreach ($items as $item) {
        $find->execute([$rootId, path_key($item['path'])]);
        $libraryFileId = (int)($find->fetchColumn() ?: 0);
        if ($libraryFileId <= 0) {
            throw new RuntimeException('Файл отсутствует в кэше библиотеки: ' . path_basename($item['path']));
        }
        lc_set_file_category($libraryFileId, $rootId, $categoryId);
        $changed++;
    }

    json_response(['ok' => true, 'updated' => $changed, 'category_id' => $categoryId]);
}

function action_move_items(): void
{
    $root = get_root_by_path($_POST['root'] ?? '', false);
    $rootPath = trim_path_separators(normalize_path($root['root_path']));
    if (!is_dir($rootPath)) throw new RuntimeException('Корневая папка недоступна.');

    $destination = trim_path_separators(normalize_path($_POST['destination'] ?? ''));
    assert_path_inside_root($rootPath, $destination, true);
    if (!is_dir($destination)) throw new RuntimeException('Папка назначения не найдена.');

    $items = normalize_selected_items(parse_items_payload(), $rootPath);
    $operations = [];
    $targetKeys = [];
    foreach ($items as $item) {
        if ($item['type'] === 'dir' && path_is_same_or_child($destination, $item['path'])) {
            throw new RuntimeException('Нельзя переместить папку внутрь самой себя.');
        }
        if (paths_equal(path_dirname($item['path']), $destination)) continue;

        $target = join_path($destination, path_basename($item['path']));
        $targetKey = path_key($target);
        if (isset($targetKeys[$targetKey])) throw new RuntimeException('Несколько выбранных объектов имеют одинаковое имя.');
        $targetKeys[$targetKey] = true;
        if (file_exists($target)) throw new RuntimeException('В папке назначения уже существует: ' . path_basename($target));
        $operations[] = ['type' => $item['type'], 'source' => $item['path'], 'target' => $target];
    }

    if (!$operations) json_response(['ok' => true, 'moved' => 0]);

    $physicallyMoved = [];
    foreach ($operations as $operation) {
        if (!@rename($operation['source'], $operation['target'])) {
            foreach (array_reverse($physicallyMoved) as $done) @rename($done['target'], $done['source']);
            throw new RuntimeException('Не удалось переместить: ' . $operation['source']);
        }
        $physicallyMoved[] = $operation;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($operations as $operation) update_cache_after_move($root, $operation);
        $pdo->prepare('UPDATE library_roots SET last_refresh_at = NOW() WHERE id = ?')->execute([(int)$root['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach (array_reverse($physicallyMoved) as $done) @rename($done['target'], $done['source']);
        throw $e;
    }

    json_response(['ok' => true, 'moved' => count($operations)]);
}

function touch_roots(array $rootIds): void
{
    $rootIds = array_values(array_unique(array_map('intval', $rootIds)));
    if (!$rootIds) return;
    foreach (array_chunk($rootIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        db()->prepare("UPDATE library_roots SET last_refresh_at = NOW() WHERE id IN ({$placeholders})")->execute($chunk);
    }
}

function remove_stale_target_cache_global(string $target, string $type): void
{
    $pdo = db();

    if ($type === 'dir') {
        $stmt = $pdo->query('SELECT id, root_path FROM library_roots');
        $staleRootIds = [];
        foreach ($stmt->fetchAll() as $row) {
            if (path_is_same_or_child($row['root_path'], $target)) $staleRootIds[] = (int)$row['id'];
        }
        delete_rows_by_ids('library_roots', $staleRootIds);
    }

    $stmt = $pdo->query('SELECT id, file_path FROM library_files');
    $fileIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $match = $type === 'file' ? paths_equal($row['file_path'], $target) : path_is_same_or_child($row['file_path'], $target);
        if ($match) $fileIds[] = (int)$row['id'];
    }
    delete_rows_by_ids('library_files', $fileIds);

    if ($type === 'dir') {
        $stmt = $pdo->query('SELECT id, dir_path FROM library_dirs');
        $dirIds = [];
        foreach ($stmt->fetchAll() as $row) {
            if (path_is_same_or_child($row['dir_path'], $target)) $dirIds[] = (int)$row['id'];
        }
        delete_rows_by_ids('library_dirs', $dirIds);
    }
}

function update_cache_after_move(array $root, array $operation): void
{
    $pdo = db();
    $source = $operation['source'];
    $target = normalize_path($operation['target']);
    $type = $operation['type'];

    remove_stale_target_cache_global($target, $type);

    $rootStmt = $pdo->query('SELECT * FROM library_roots');
    $roots = $rootStmt->fetchAll();
    $rootPaths = [];
    $affectedRootIds = [];

    foreach ($roots as $cachedRoot) {
        $rootId = (int)$cachedRoot['id'];
        $newRootPath = $cachedRoot['root_path'];
        if ($type === 'dir' && path_is_same_or_child($cachedRoot['root_path'], $source)) {
            $newRootPath = replace_path_prefix($cachedRoot['root_path'], $source, $target);
            $pdo->prepare('UPDATE library_roots SET root_path = ?, root_key = ?, last_refresh_at = NOW() WHERE id = ?')->execute([
                $newRootPath, root_key($newRootPath), $rootId,
            ]);
            $affectedRootIds[] = $rootId;
        }
        $rootPaths[$rootId] = $newRootPath;
    }

    $fileStmt = $pdo->query('SELECT * FROM library_files');
    foreach ($fileStmt->fetchAll() as $row) {
        $matches = $type === 'file'
            ? paths_equal($row['file_path'], $source)
            : path_is_same_or_child($row['file_path'], $source);
        if (!$matches) continue;

        $newPath = $type === 'file' ? $target : replace_path_prefix($row['file_path'], $source, $target);
        $rootPath = $rootPaths[(int)$row['root_id']] ?? null;
        if (!$rootPath) continue;
        $mtime = is_file($newPath) ? (int)(filemtime($newPath) ?: $row['file_mtime']) : (int)$row['file_mtime'];
        $pdo->prepare('UPDATE library_files SET relative_path = ?, file_path = ?, path_key = ?, file_name = ?, file_mtime = ? WHERE id = ?')->execute([
            relative_path_from_root($rootPath, $newPath), $newPath, path_key($newPath), path_basename($newPath), $mtime, $row['id'],
        ]);
        update_card_location_if_exists($row['file_hash'], $newPath);
        $affectedRootIds[] = (int)$row['root_id'];
    }

    if ($type === 'dir') {
        $dirStmt = $pdo->query('SELECT * FROM library_dirs');
        $movedRootDirKeys = [];
        foreach ($dirStmt->fetchAll() as $row) {
            if (!path_is_same_or_child($row['dir_path'], $source)) continue;
            $newPath = replace_path_prefix($row['dir_path'], $source, $target);
            $rootPath = $rootPaths[(int)$row['root_id']] ?? null;
            if (!$rootPath) continue;
            $pdo->prepare('UPDATE library_dirs SET relative_path = ?, dir_path = ?, path_key = ?, dir_name = ? WHERE id = ?')->execute([
                relative_path_from_root($rootPath, $newPath), $newPath, path_key($newPath), path_basename($newPath), $row['id'],
            ]);
            if (paths_equal($row['dir_path'], $source)) $movedRootDirKeys[(int)$row['root_id']] = true;
            $affectedRootIds[] = (int)$row['root_id'];
        }

        // Add the moved folder to ancestor caches that did not have an explicit directory row.
        $insert = $pdo->prepare(
            'INSERT INTO library_dirs (root_id, relative_path, dir_path, path_key, dir_name, last_scan_token)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE relative_path = VALUES(relative_path), dir_path = VALUES(dir_path), dir_name = VALUES(dir_name), last_seen_at = CURRENT_TIMESTAMP'
        );
        foreach ($rootPaths as $rootId => $rootPath) {
            if (!path_is_same_or_child($target, $rootPath) || paths_equal($target, $rootPath)) continue;
            if (isset($movedRootDirKeys[$rootId])) continue;
            $insert->execute([
                $rootId, relative_path_from_root($rootPath, $target), $target, path_key($target), path_basename($target), '',
            ]);
            $affectedRootIds[] = $rootId;
        }
    }

    touch_roots($affectedRootIds);
}

function action_delete_items(): void
{
    $root = get_root_by_path($_POST['root'] ?? '', false);
    $rootPath = trim_path_separators(normalize_path($root['root_path']));
    $items = normalize_selected_items(parse_items_payload(), $rootPath);

    $trash = join_path($rootPath, '.video_catalog_delete_' . bin2hex(random_bytes(8)));
    if (!@mkdir($trash, 0775, false)) throw new RuntimeException('Не удалось подготовить безопасное удаление.');

    $moved = [];
    foreach ($items as $index => $item) {
        $target = join_path($trash, sprintf('%04d_%s', $index, path_basename($item['path'])));
        if (!@rename($item['path'], $target)) {
            foreach (array_reverse($moved) as $done) @rename($done['target'], $done['source']);
            @rmdir($trash);
            throw new RuntimeException('Не удалось удалить: ' . $item['path']);
        }
        $moved[] = ['type' => $item['type'], 'source' => $item['path'], 'target' => $target];
    }

    $pdo = db();
    $imageFiles = [];
    $pdo->beginTransaction();
    try {
        $hashes = remove_items_from_database($root, $items);
        foreach ($hashes as $hash) {
            $remainingStmt = $pdo->prepare('SELECT file_path FROM library_files WHERE file_hash = ? ORDER BY id LIMIT 1');
            $remainingStmt->execute([$hash]);
            $remainingPath = $remainingStmt->fetchColumn();
            if ($remainingPath) {
                update_card_location_if_exists($hash, $remainingPath);
                continue;
            }

            $cardStmt = $pdo->prepare('SELECT id FROM file_cards WHERE file_hash = ?');
            $cardStmt->execute([$hash]);
            $cardId = $cardStmt->fetchColumn();
            if (!$cardId) continue;

            $imageStmt = $pdo->prepare('SELECT filename FROM file_images WHERE card_id = ?');
            $imageStmt->execute([(int)$cardId]);
            foreach ($imageStmt->fetchAll() as $image) $imageFiles[] = $image['filename'];
            $pdo->prepare('DELETE FROM file_cards WHERE id = ?')->execute([(int)$cardId]);
        }
        $pdo->prepare('UPDATE library_roots SET last_refresh_at = NOW() WHERE id = ?')->execute([(int)$root['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach (array_reverse($moved) as $done) @rename($done['target'], $done['source']);
        @rmdir($trash);
        throw $e;
    }

    foreach ($imageFiles as $filename) {
        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) @unlink($path);
    }
    if (!remove_path_recursive($trash)) {
        throw new RuntimeException('База обновлена, но временную папку не удалось окончательно очистить: ' . $trash);
    }

    cleanup_orphan_root_video_screenshots();
    json_response(['ok' => true, 'deleted' => count($items)]);
}

function remove_items_from_database(array $root, array $items): array
{
    $pdo = db();
    $allFiles = $pdo->query('SELECT id, root_id, file_path, file_hash FROM library_files')->fetchAll();
    $allDirs = $pdo->query('SELECT id, root_id, dir_path FROM library_dirs')->fetchAll();
    $allRoots = $pdo->query('SELECT id, root_path FROM library_roots')->fetchAll();

    $fileIds = [];
    $dirIds = [];
    $rootIds = [];
    $affectedRootIds = [];
    $hashes = [];

    foreach ($items as $item) {
        foreach ($allFiles as $row) {
            $match = $item['type'] === 'file'
                ? paths_equal($row['file_path'], $item['path'])
                : path_is_same_or_child($row['file_path'], $item['path']);
            if (!$match) continue;
            $fileIds[(int)$row['id']] = (int)$row['id'];
            $affectedRootIds[(int)$row['root_id']] = (int)$row['root_id'];
            $hashes[$row['file_hash']] = $row['file_hash'];
        }

        if ($item['type'] !== 'dir') continue;
        foreach ($allDirs as $row) {
            if (!path_is_same_or_child($row['dir_path'], $item['path'])) continue;
            $dirIds[(int)$row['id']] = (int)$row['id'];
            $affectedRootIds[(int)$row['root_id']] = (int)$row['root_id'];
        }
        foreach ($allRoots as $cachedRoot) {
            if (path_is_same_or_child($cachedRoot['root_path'], $item['path'])) {
                $rootIds[(int)$cachedRoot['id']] = (int)$cachedRoot['id'];
            }
        }
    }

    delete_rows_by_ids('library_files', array_values($fileIds));
    delete_rows_by_ids('library_dirs', array_values($dirIds));
    delete_rows_by_ids('library_roots', array_values($rootIds));
    touch_roots(array_values(array_diff($affectedRootIds, $rootIds)));
    return array_values($hashes);
}

function delete_rows_by_ids(string $table, array $ids): void
{
    if (!$ids) return;
    if (!in_array($table, ['library_files', 'library_dirs', 'library_roots'], true)) throw new RuntimeException('Недопустимая таблица.');
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        if ($table === 'library_roots') {
            db()->prepare("DELETE FROM categories WHERE root_id IN ({$placeholders})")->execute($chunk);
        }
        db()->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})")->execute($chunk);
    }
}

function remove_path_recursive(string $path): bool
{
    if (is_file($path) || is_link($path)) return @unlink($path);
    if (!is_dir($path)) return true;

    $entries = @scandir($path);
    if ($entries === false) return false;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!remove_path_recursive(join_path($path, $entry))) return false;
    }
    return @rmdir($path);
}
