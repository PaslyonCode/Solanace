<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library_identity.php';
auth_require_stream();

set_time_limit(0);
ignore_user_abort(false);
ini_set('zlib.output_compression', 'Off');
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$path = normalize_path(base64url_decode($_GET['token'] ?? ''));
if ($path === '') {
    http_response_code(404);
    exit('Файл не найден');
}
// A token is only an opaque UI identifier, not permission to read an arbitrary
// server path. Serve only files currently registered in library_files.
$stmt = db()->prepare(
    'SELECT lf.file_path FROM library_files lf WHERE lf.path_key = ? LIMIT 1'
);
$stmt->execute([li_path_key($path)]);
$registered = $stmt->fetchColumn();
if (!$registered) {
    http_response_code(403);
    exit('Доступ к файлу запрещен');
}
$registeredPath = normalize_path((string)$registered);
if (li_path_key($registeredPath) !== li_path_key($path) || !is_file($registeredPath) || !is_readable($registeredPath)) {
    http_response_code(404);
    exit('Файл не найден или недоступен для чтения');
}
$path = $registeredPath;

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'mp4' => 'video/mp4',
    'm4v' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'mpeg' => 'video/mpeg',
    'mpg' => 'video/mpeg',
    'avi' => 'video/x-msvideo',
    'wmv' => 'video/x-ms-wmv',
    'mkv' => 'video/x-matroska',
    'flv' => 'video/x-flv',
    'ts' => 'video/mp2t',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';
$size = filesize($path);
if ($size === false || $size < 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Файл пуст или недоступен';
    exit;
}

$size = (int)$size;
$start = 0;
$end = $size - 1;
$partial = false;
$forFrames = ($_GET['frames'] ?? '') === '1';
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

if ($rangeHeader !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/i', trim($rangeHeader), $match)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }

    $partial = true;
    $rawStart = $match[1];
    $rawEnd = $match[2];

    if ($rawStart === '' && $rawEnd !== '') {
        // Suffix range: bytes=-500 means the last 500 bytes.
        $suffixLength = min((int)$rawEnd, $size);
        $start = max(0, $size - $suffixLength);
        $end = $size - 1;
    } else {
        $start = $rawStart === '' ? 0 : (int)$rawStart;
        $end = $rawEnd === '' ? $size - 1 : (int)$rawEnd;

        // Для генератора кадров браузер часто запрашивает bytes=N-.
        // Не держим один PHP-запрос открытым до конца многогигабайтного файла:
        // отдаем ограниченный блок, после чего браузер запросит следующий range.
        if ($forFrames && $rawEnd === '') {
            $chunkLimit = 8 * 1024 * 1024;
            $end = min($end, $start + $chunkLimit - 1);
        }
    }

    if ($start < 0 || $start >= $size || $end < $start) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $end = min($end, $size - 1);
}

$length = $end - $start + 1;
$filename = basename($path);
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'video';

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . addslashes($asciiName) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($partial) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}
header('Content-Length: ' . $length);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

if ($start > 0 && fseek($handle, $start) !== 0) {
    fclose($handle);
    http_response_code(500);
    exit;
}

$remaining = $length;
$bufferSize = 1024 * 1024;
while ($remaining > 0 && !feof($handle)) {
    if (connection_aborted()) break;
    $readLength = min($bufferSize, $remaining);
    $data = fread($handle, $readLength);
    if ($data === false || $data === '') break;
    echo $data;
    $remaining -= strlen($data);
    flush();
}

fclose($handle);
