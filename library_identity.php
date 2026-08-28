<?php
require_once __DIR__ . '/db.php';

function li_normalize_root_path(string $path): string
{
    $path = trim($path, " \t\n\r\0\x0B\"'");
    if ($path === '') return '';
    $real = realpath($path);
    $path = $real !== false ? $real : $path;
    if (preg_match('/^[A-Za-z]:[\\\\\/]*$/', $path)) return strtoupper($path[0]) . ':\\';
    return rtrim($path, "\\/");
}

function li_path_key(string $path): string
{
    $canonical = str_replace('/', '\\', li_normalize_root_path($path));
    if (DIRECTORY_SEPARATOR === '\\') $canonical = mb_strtolower($canonical, 'UTF-8');
    return sha1($canonical);
}

function li_path_is_allowed(string $path): bool
{
    $allowed = defined('ALLOWED_MEDIA_ROOTS') && is_array(ALLOWED_MEDIA_ROOTS) ? ALLOWED_MEDIA_ROOTS : [];
    if (!$allowed) return true;
    $candidate = str_replace('\\', '/', li_normalize_root_path($path));
    if (DIRECTORY_SEPARATOR === '\\') $candidate = mb_strtolower($candidate, 'UTF-8');
    foreach ($allowed as $base) {
        $base = str_replace('\\', '/', li_normalize_root_path((string)$base));
        if ($base === '') continue;
        if (DIRECTORY_SEPARATOR === '\\') $base = mb_strtolower($base, 'UTF-8');
        $base = rtrim($base, '/');
        if ($candidate === $base || str_starts_with($candidate, $base . '/')) return true;
    }
    return false;
}

function li_assert_allowed_root(string $path): void
{
    if (!li_path_is_allowed($path)) {
        throw new RuntimeException('Эта папка находится вне разрешенных медиа-каталогов сервера.');
    }
}

function li_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function li_valid_uid(string $uid): bool
{
    return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', trim($uid));
}

function li_screenshot_dir(string $rootPath): string
{
    return li_normalize_root_path($rootPath) . DIRECTORY_SEPARATOR . VIDEO_SCREENSHOT_DIRNAME;
}

function li_marker_path(string $rootPath): string
{
    return li_screenshot_dir($rootPath) . DIRECTORY_SEPARATOR . 'library.id';
}

function li_write_service_dir_protection(string $dir): void
{
    // Harmless on non-Apache servers; useful if a media root happens to be under DocumentRoot.
    $file = rtrim($dir, "\/") . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($file)) return;
    @file_put_contents($file, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n", LOCK_EX);
}

function li_read_marker(string $rootPath): ?string
{
    $file = li_marker_path($rootPath);
    if (!is_file($file) || !is_readable($file)) return null;
    $uid = trim((string)@file_get_contents($file));
    return li_valid_uid($uid) ? strtolower($uid) : null;
}

function li_write_marker(string $rootPath, string $uid): void
{
    if (!li_valid_uid($uid)) throw new RuntimeException('Некорректный идентификатор библиотеки.');
    $dir = li_screenshot_dir($rootPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать служебную папку библиотеки: ' . $dir);
    }
    li_write_service_dir_protection($dir);
    $marker = li_marker_path($rootPath);
    $current = is_file($marker) ? trim((string)@file_get_contents($marker)) : '';
    if (strtolower($current) === strtolower($uid)) return;
    if (@file_put_contents($marker, strtolower($uid) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать идентификатор библиотеки: ' . $marker);
    }
}

function li_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();
    $column = $pdo->query("SHOW COLUMNS FROM library_roots LIKE 'library_uid'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE library_roots ADD COLUMN library_uid CHAR(36) NULL AFTER root_key");
    }

    $index = $pdo->query("SHOW INDEX FROM library_roots WHERE Key_name = 'uq_library_roots_uid'")->fetch();
    if (!$index) {
        $pdo->exec("CREATE UNIQUE INDEX uq_library_roots_uid ON library_roots (library_uid)");
    }

    $rows = $pdo->query("SELECT id FROM library_roots WHERE library_uid IS NULL OR library_uid = ''")->fetchAll();
    $update = $pdo->prepare('UPDATE library_roots SET library_uid = ? WHERE id = ?');
    foreach ($rows as $row) $update->execute([li_uuid(), (int)$row['id']]);

    // Best effort: immediately place identity markers into every currently accessible cached root.
    // This makes old libraries path-independent as soon as the upgraded application is opened.
    foreach ($pdo->query('SELECT root_path, library_uid FROM library_roots')->fetchAll() as $root) {
        if (!is_dir((string)$root['root_path']) || !li_valid_uid((string)$root['library_uid'])) continue;
        try {
            li_write_marker((string)$root['root_path'], (string)$root['library_uid']);
        } catch (Throwable $ignored) {
            // A particular offline/read-only root must not prevent the rest of the application from starting.
        }
    }
    $done = true;
}

function li_join_root_relative(string $rootPath, string $relativePath): string
{
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, "\\/"));
    return li_normalize_root_path($rootPath) . ($relativePath !== '' ? DIRECTORY_SEPARATOR . $relativePath : '');
}

function li_relocate_root(array $root, string $newPath): array
{
    $newPath = li_normalize_root_path($newPath);
    li_assert_allowed_root($newPath);
    $oldPath = li_normalize_root_path((string)$root['root_path']);
    if ($newPath === '' || !is_dir($newPath)) throw new RuntimeException('Новая корневая папка недоступна: ' . $newPath);
    if (li_path_key($oldPath) === li_path_key($newPath)) {
        li_write_marker($newPath, (string)$root['library_uid']);
        $root['root_path'] = $newPath;
        return $root;
    }

    $pdo = db();
    $newKey = li_path_key($newPath);
    $conflict = $pdo->prepare('SELECT id FROM library_roots WHERE root_key = ? AND id <> ? LIMIT 1');
    $conflict->execute([$newKey, (int)$root['id']]);
    if ($conflict->fetchColumn()) {
        throw new RuntimeException('По новому пути уже зарегистрирована другая корневая библиотека.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE library_roots SET root_path = ?, root_key = ? WHERE id = ?')
            ->execute([$newPath, $newKey, (int)$root['id']]);

        $files = $pdo->prepare('SELECT id, relative_path, file_hash FROM library_files WHERE root_id = ?');
        $files->execute([(int)$root['id']]);
        $updateFile = $pdo->prepare('UPDATE library_files SET file_path = ?, path_key = ?, file_name = ? WHERE id = ?');
        $updateCard = $pdo->prepare('UPDATE file_cards SET file_path = ? WHERE file_hash = ?');
        foreach ($files->fetchAll() as $file) {
            $path = li_join_root_relative($newPath, (string)$file['relative_path']);
            $updateFile->execute([$path, li_path_key($path), basename(str_replace('\\', '/', $path)), (int)$file['id']]);
            $updateCard->execute([$path, (string)$file['file_hash']]);
        }

        $dirs = $pdo->prepare('SELECT id, relative_path FROM library_dirs WHERE root_id = ?');
        $dirs->execute([(int)$root['id']]);
        $updateDir = $pdo->prepare('UPDATE library_dirs SET dir_path = ?, path_key = ?, dir_name = ? WHERE id = ?');
        foreach ($dirs->fetchAll() as $dir) {
            $path = li_join_root_relative($newPath, (string)$dir['relative_path']);
            $updateDir->execute([$path, li_path_key($path), basename(str_replace('\\', '/', $path)), (int)$dir['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    li_write_marker($newPath, (string)$root['library_uid']);
    $root['root_path'] = $newPath;
    $root['root_key'] = $newKey;
    $root['_relocated_from'] = $oldPath;
    return $root;
}

function li_resolve_root(string $requestedPath, bool $createIfMissing = true): array
{
    li_ensure_schema();
    $requestedPath = li_normalize_root_path($requestedPath);
    if ($requestedPath === '') throw new RuntimeException('Папка не указана.');
    li_assert_allowed_root($requestedPath);

    $pdo = db();
    $exists = is_dir($requestedPath);
    $markerUid = $exists ? li_read_marker($requestedPath) : null;
    $pathKey = li_path_key($requestedPath);

    $byPathStmt = $pdo->prepare('SELECT * FROM library_roots WHERE root_key = ? LIMIT 1');
    $byPathStmt->execute([$pathKey]);
    $byPath = $byPathStmt->fetch() ?: null;

    if ($markerUid !== null) {
        $byUidStmt = $pdo->prepare('SELECT * FROM library_roots WHERE library_uid = ? LIMIT 1');
        $byUidStmt->execute([$markerUid]);
        $byUid = $byUidStmt->fetch() ?: null;

        if ($byUid) {
            if ($byPath && (int)$byPath['id'] !== (int)$byUid['id']) {
                throw new RuntimeException('Конфликт идентификаторов библиотек: этот путь уже связан с другой записью базы.');
            }
            return li_relocate_root($byUid, $requestedPath);
        }

        if ($byPath) {
            // Старая БД могла знать путь, но еще не иметь ID-маркера. Сохраняем запись БД и синхронизируем маркер.
            li_write_marker($requestedPath, (string)$byPath['library_uid']);
            return $byPath;
        }

        if (!$createIfMissing) throw new RuntimeException('Папка отсутствует в кэше.');
        if (!$exists) throw new RuntimeException('Папка не найдена: ' . $requestedPath);
        $insert = $pdo->prepare('INSERT INTO library_roots (root_path, root_key, library_uid) VALUES (?, ?, ?)');
        $insert->execute([$requestedPath, $pathKey, $markerUid]);
        $stmt = $pdo->prepare('SELECT * FROM library_roots WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        return $stmt->fetch();
    }

    if ($byPath) {
        if (!$exists && !$createIfMissing) return $byPath;
        if ($exists) li_write_marker($requestedPath, (string)$byPath['library_uid']);
        return $byPath;
    }

    if (!$createIfMissing) throw new RuntimeException('Папка отсутствует в кэше.');
    if (!$exists) throw new RuntimeException('Папка не найдена: ' . $requestedPath);

    $uid = li_uuid();
    $insert = $pdo->prepare('INSERT INTO library_roots (root_path, root_key, library_uid) VALUES (?, ?, ?)');
    $insert->execute([$requestedPath, $pathKey, $uid]);
    li_write_marker($requestedPath, $uid);
    $stmt = $pdo->prepare('SELECT * FROM library_roots WHERE id = ?');
    $stmt->execute([(int)$pdo->lastInsertId()]);
    return $stmt->fetch();
}
