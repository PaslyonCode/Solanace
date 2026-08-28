<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
auth_require_stream();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Screenshot not specified');
}

$stmt = db()->prepare(
    'SELECT rvs.relative_path, lr.root_path
     FROM root_video_screenshots rvs
     INNER JOIN library_roots lr ON lr.id = rvs.root_id
     INNER JOIN root_video_screenshot_sets rvss
        ON rvss.root_id = rvs.root_id
       AND rvss.file_hash = rvs.file_hash
       AND rvss.status = \'ready\'
     WHERE rvs.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Screenshot not found');
}

$relative = str_replace('\\', '/', trim((string)$row['relative_path']));
if ($relative === '' || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
    http_response_code(403);
    exit('Invalid screenshot path');
}

$base = rtrim((string)$row['root_path'], "\\/") . DIRECTORY_SEPARATOR . VIDEO_SCREENSHOT_DIRNAME;
$file = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
$realBase = realpath($base);
$realFile = realpath($file);

if ($realBase === false || $realFile === false || !is_file($realFile)) {
    http_response_code(404);
    exit('Screenshot file not found');
}

$baseComparable = str_replace('\\', '/', rtrim($realBase, "\\/"));
$fileComparable = str_replace('\\', '/', $realFile);
if (DIRECTORY_SEPARATOR === '\\') {
    $baseComparable = mb_strtolower($baseComparable, 'UTF-8');
    $fileComparable = mb_strtolower($fileComparable, 'UTF-8');
}
if (!str_starts_with($fileComparable, $baseComparable . '/')) {
    http_response_code(403);
    exit('Access denied');
}

// Tile mode asks for a much smaller decoded image. Cache it next to the source
// frame so a large 1280px JPEG is not decoded for every visible catalog tile.
$wantThumb = ($_GET['thumb'] ?? '') === '1';
$serveFile = $realFile;
if ($wantThumb && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
    $thumbFile = dirname($realFile) . DIRECTORY_SEPARATOR . '.tile_' . basename($realFile);
    $needsBuild = !is_file($thumbFile) || filemtime($thumbFile) < filemtime($realFile);
    if ($needsBuild) {
        $source = @imagecreatefromjpeg($realFile);
        if ($source !== false) {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $targetWidth = min(360, max(1, $sourceWidth));
            $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / max(1, $sourceWidth))));
            $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($thumb !== false) {
                imagecopyresampled(
                    $thumb,
                    $source,
                    0, 0, 0, 0,
                    $targetWidth, $targetHeight,
                    $sourceWidth, $sourceHeight
                );
                @imagejpeg($thumb, $thumbFile, 82);
                imagedestroy($thumb);
            }
            imagedestroy($source);
        }
    }
    if (is_file($thumbFile) && filesize($thumbFile) > 0) $serveFile = $thumbFile;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($serveFile) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($serveFile));
header('Content-Disposition: inline; filename="' . basename($serveFile) . '"');
header('Cache-Control: private, max-age=86400');
readfile($serveFile);
