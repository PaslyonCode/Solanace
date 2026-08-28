<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/file_tools_lib.php';
auth_require_stream();
ft_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM file_derivatives WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Файл не найден');
}
$path = ft_derivative_absolute_path($row);
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Файл не найден');
}

set_time_limit(0);
ignore_user_abort(false);
ini_set('zlib.output_compression', 'Off');
while (ob_get_level() > 0) @ob_end_clean();

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
    'mov' => 'video/quicktime', 'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg',
    'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'mkv' => 'video/x-matroska',
    'flv' => 'video/x-flv', 'ts' => 'video/mp2t', 'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'txt' => 'text/plain; charset=utf-8',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';
$size = (int)(filesize($path) ?: 0);
if ($size < 1) { http_response_code(404); exit('Файл пуст'); }

$start = 0;
$end = $size - 1;
$partial = false;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $m)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $partial = true;
    if ($m[1] === '' && $m[2] !== '') {
        $suffix = min((int)$m[2], $size);
        $start = max(0, $size - $suffix);
    } else {
        $start = $m[1] === '' ? 0 : (int)$m[1];
        $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    }
    if ($start < 0 || $start >= $size || $end < $start) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
}
$length = $end - $start + 1;
$name = (string)$row['download_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';
$inline = ($_GET['inline'] ?? '') === '1';
$download = ($_GET['download'] ?? '') === '1';
$disposition = ($download || !$inline) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
if ($partial) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}
header('Content-Length: ' . $length);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

$fp = fopen($path, 'rb');
if (!$fp) { http_response_code(500); exit; }
if ($start > 0) fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    if (connection_aborted()) break;
    $data = fread($fp, min(1024 * 1024, $remaining));
    if ($data === false || $data === '') break;
    echo $data;
    $remaining -= strlen($data);
    flush();
}
fclose($fp);
