<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
auth_require_stream();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}
$stmt = db()->prepare('SELECT filename FROM file_images WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$filename = (string)($stmt->fetchColumn() ?: '');
if ($filename === '' || basename($filename) !== $filename) {
    http_response_code(404);
    exit;
}
$base = realpath(UPLOAD_DIR);
$file = realpath(UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename);
if ($base === false || $file === false || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit;
}
$baseCmp = str_replace('\\', '/', rtrim($base, "\\/"));
$fileCmp = str_replace('\\', '/', $file);
if (DIRECTORY_SEPARATOR === '\\') {
    $baseCmp = mb_strtolower($baseCmp, 'UTF-8');
    $fileCmp = mb_strtolower($fileCmp, 'UTF-8');
}
if (!str_starts_with($fileCmp, $baseCmp . '/')) {
    http_response_code(403);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
    http_response_code(403);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="image"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file);
