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

    $rootPath = screenshotViewNormalizeRoot((string)($_GET['root'] ?? ''));
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

    $fileStmt = $pdo->prepare(<<<SQL
SELECT
    lf.file_path,
    lf.file_name,
    lf.relative_path,
    lf.file_hash,
    fc.custom_title,
    c.name AS category_name,
    rvss.status AS screenshot_status,
    rvss.expected_count,
    rvss.last_error,
    COALESCE(rvss.thumbnail_sort_order, 1) AS thumbnail_sort_order
FROM library_files lf
LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
LEFT JOIN library_file_categories lfc ON lfc.library_file_id = lf.id
LEFT JOIN categories c ON c.id = lfc.category_id AND c.root_id = lf.root_id
LEFT JOIN root_video_screenshot_sets rvss
    ON rvss.root_id = lf.root_id
   AND rvss.file_hash = lf.file_hash
WHERE lf.root_id = ?
ORDER BY
    CASE WHEN COALESCE(fc.custom_title, '') = '' THEN lf.file_name ELSE fc.custom_title END,
    lf.relative_path
SQL
    );
    $fileStmt->execute([(int)$root['id']]);

    $rowsByHash = [];
    $order = [];
    while ($row = $fileStmt->fetch()) {
        $hash = (string)$row['file_hash'];
        $customTitle = trim((string)($row['custom_title'] ?? ''));
        $fileName = (string)$row['file_name'];
        $rowsByHash[$hash] = [
            'token' => base64url_encode((string)$row['file_path']),
            'display_title' => $customTitle !== '' ? $customTitle : $fileName,
            'custom_title' => $customTitle,
            'file_name' => $fileName,
            'relative_path' => (string)$row['relative_path'],
            'category_name' => (string)($row['category_name'] ?? ''),
            'screenshot_status' => (string)($row['screenshot_status'] ?? 'missing'),
            'expected_count' => (int)($row['expected_count'] ?? 10),
            'last_error' => (string)($row['last_error'] ?? ''),
            'thumbnail_sort_order' => (int)($row['thumbnail_sort_order'] ?? 1),
            'screenshots' => [],
        ];
        $order[] = $hash;
    }

    if ($rowsByHash) {
        $shotStmt = $pdo->prepare(<<<SQL
SELECT
    rvs.id,
    rvs.file_hash,
    rvs.position_seconds,
    rvs.sort_order
FROM root_video_screenshots rvs
INNER JOIN root_video_screenshot_sets rvss
    ON rvss.root_id = rvs.root_id
   AND rvss.file_hash = rvs.file_hash
   AND rvss.status = 'ready'
WHERE rvs.root_id = ?
ORDER BY rvs.file_hash, rvs.sort_order
SQL
        );
        $shotStmt->execute([(int)$root['id']]);
        while ($shot = $shotStmt->fetch()) {
            $hash = (string)$shot['file_hash'];
            if (!isset($rowsByHash[$hash])) continue;
            if (count($rowsByHash[$hash]['screenshots']) >= 5) continue;
            $rowsByHash[$hash]['screenshots'][] = [
                'id' => (int)$shot['id'],
                'position_seconds' => (float)$shot['position_seconds'],
                'sort_order' => (int)$shot['sort_order'],
                'is_thumbnail' => (int)$shot['sort_order'] === (int)$rowsByHash[$hash]['thumbnail_sort_order'],
                'url' => 'screenshot.php?id=' . (int)$shot['id'],
            ];
        }
    }

    $rows = [];
    foreach ($order as $hash) {
        $rows[] = $rowsByHash[$hash];
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

function screenshotViewNormalizeRoot(string $path): string
{
    $path = trim($path, " \t\n\r\0\x0B\"'");
    if ($path === '') return '';

    $real = realpath($path);
    $path = $real !== false ? $real : $path;

    if (preg_match('/^[A-Za-z]:[\\\\\/]*$/', $path)) {
        return strtoupper($path[0]) . ':\\';
    }

    return rtrim($path, "\\/");
}
