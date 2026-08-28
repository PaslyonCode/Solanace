<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/translation_lib.php';
auth_require_stream();
ob_start();
try {
    $data=tl_translation_for_download((int)($_GET['id']??0));
    // Never let PHP warnings/notices become part of a downloaded TXT file.
    if (ob_get_length()) ob_clean();
    $body = "\xEF\xBB\xBF" . $data['body']; // UTF-8 BOM for Windows editors.
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="translation.txt"; filename*=UTF-8\'\'' . rawurlencode($data['name']));
    header('Content-Length: ' . strlen($body));
    echo $body;
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
}
