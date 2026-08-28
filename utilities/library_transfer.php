<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../library_transfer_lib.php';

ob_start();
auth_require_json();

try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    $root = trim((string)($_POST['root'] ?? $_GET['root'] ?? ''));
    if ($root === '') throw new RuntimeException('Сначала выберите корневую папку библиотеки.');

    if ($action === 'export') {
        $result = lt_export_library($root);
        json_response(['ok'=>true,'export'=>$result]);
    }

    if ($action === 'list_exports') {
        json_response(['ok'=>true,'exports'=>lt_list_exports($root)]);
    }

    if ($action === 'import') {
        $zipPath = '';
        $serverName = trim((string)($_POST['server_zip'] ?? ''));
        if ($serverName !== '') {
            $name = lt_validate_zip_name($serverName);
            $resolved = li_resolve_root($root, false);
            $candidate = li_normalize_root_path((string)$resolved['root_path']) . DIRECTORY_SEPARATOR . $name;
            $base = realpath((string)$resolved['root_path']);
            $real = realpath($candidate);
            if ($base === false || $real === false || !is_file($real)) throw new RuntimeException('Выбранный архив не найден в корне библиотеки.');
            $baseCmp = str_replace('\\','/',rtrim($base,"\\/"));
            $realCmp = str_replace('\\','/',$real);
            if (DIRECTORY_SEPARATOR === '\\') { $baseCmp=mb_strtolower($baseCmp,'UTF-8');$realCmp=mb_strtolower($realCmp,'UTF-8'); }
            if (!str_starts_with($realCmp,$baseCmp.'/')) throw new RuntimeException('Архив находится вне выбранной библиотеки.');
            $zipPath=$real;
        } elseif (isset($_FILES['archive']) && is_array($_FILES['archive']) && (int)($_FILES['archive']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp=(string)$_FILES['archive']['tmp_name'];
            $name=(string)$_FILES['archive']['name'];
            if (strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip') throw new RuntimeException('Для импорта нужен ZIP-архив.');
            if (!is_uploaded_file($tmp)) throw new RuntimeException('Некорректная загрузка ZIP-файла.');
            $zipPath=$tmp;
        } else {
            throw new RuntimeException('Выберите архив Solanace для импорта.');
        }
        $pathPrefix = trim((string)($_POST['path_prefix'] ?? ''));
        $result=lt_import_library($root,$zipPath,$pathPrefix);
        json_response(['ok'=>true,'import'=>$result]);
    }

    throw new RuntimeException('Неизвестное действие экспорта/импорта.');
} catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()],400);
}
