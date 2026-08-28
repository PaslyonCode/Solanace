<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/library_identity.php';
require_once dirname(__DIR__) . '/library_categories.php';
auth_require_json();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response(['ok' => false, 'error' => 'Разрешен только GET-запрос.'], 405);
    }

    $rootPath = metadataViewNormalizeRoot((string)($_GET['root'] ?? ''));
    if ($rootPath === '') {
        throw new RuntimeException('Сначала выберите корневую папку каталога.');
    }

    $pdo = db();
    lc_ensure_schema();
    try {
        $root = li_resolve_root($rootPath, false);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Эта папка еще не добавлена в кэш. Сначала откройте ее в каталоге или нажмите «Обновить кэш».'
        );
    }

    $stmt = $pdo->prepare(<<<SQL
SELECT
    lf.file_path,
    lf.file_name,
    lf.relative_path,
    lf.file_hash,
    fc.custom_title,
    fc.note,
    c.name AS category_name
FROM library_files lf
LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
LEFT JOIN library_file_categories lfc ON lfc.library_file_id = lf.id
LEFT JOIN categories c ON c.id = lfc.category_id AND c.root_id = lf.root_id
WHERE lf.root_id = ?
ORDER BY
    CASE WHEN COALESCE(fc.custom_title, '') = '' THEN lf.file_name ELSE fc.custom_title END,
    lf.relative_path
SQL
    );
    $stmt->execute([(int)$root['id']]);

    $rows = [];
    while ($row = $stmt->fetch()) {
        $customTitle = trim((string)($row['custom_title'] ?? ''));
        $fileName = (string)$row['file_name'];
        $rows[] = [
            'token' => base64url_encode((string)$row['file_path']),
            'display_title' => $customTitle !== '' ? $customTitle : $fileName,
            'custom_title' => $customTitle,
            'file_name' => $fileName,
            'relative_path' => (string)$row['relative_path'],
            'note' => (string)($row['note'] ?? ''),
            'category_name' => (string)($row['category_name'] ?? ''),
            'has_card' => $row['custom_title'] !== null || $row['note'] !== null || $row['category_name'] !== null,
        ];
    }

    json_response([
        'ok' => true,
        'root' => (string)$root['root_path'],
        'last_refresh_at' => $root['last_refresh_at'],
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (Throwable $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}

function metadataViewNormalizeRoot(string $path): string
{
    $path = trim($path, " \t\n\r\0\x0B\"'");
    if ($path === '') return '';

    $real = realpath($path);
    $path = $real !== false ? $real : $path;

    if (preg_match('/^[A-Za-z]:[\\\/]*$/', $path)) {
        return strtoupper($path[0]) . ':\\';
    }

    return rtrim($path, "\\/");
}

function metadataViewPathKey(string $path): string
{
    $path = metadataViewNormalizeRoot($path);
    $canonical = str_replace('/', '\\', $path);
    if (DIRECTORY_SEPARATOR === '\\') {
        $canonical = mb_strtolower($canonical, 'UTF-8');
    }
    return sha1($canonical);
}
