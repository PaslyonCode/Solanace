<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/translation_lib.php';
auth_require_json();
ob_start();
tl_ensure_schema();
$action=$_GET['action']??$_POST['action']??'';
try {
    if ($action==='settings') json_response(['ok'=>true,'settings'=>tl_public_settings()]);
    if ($action==='save_settings') {
        $settings=tl_save_settings((string)($_POST['provider']??'groq'),(string)($_POST['model']??''),(string)($_POST['api_key']??''),(string)($_POST['python_path']??''));
        json_response(['ok'=>true,'settings'=>$settings]);
    }
    if ($action==='start') {
        $job=tl_create_job((int)($_POST['transcript_id']??0),(string)($_POST['target_language']??''));
        json_response(['ok'=>true,'job'=>$job]);
    }
    if ($action==='import_custom') {
        $transcriptId=(int)($_POST['transcript_id']??0);
        $customName=trim((string)($_POST['custom_name']??''));
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) throw new RuntimeException('Выберите TXT-файл перевода.');
        $upload=$_FILES['file'];
        if ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить TXT-файл.');
        if ((int)($upload['size']??0)>5*1024*1024) throw new RuntimeException('TXT-файл слишком большой (максимум 5 МБ).');
        $tmp=(string)($upload['tmp_name']??'');
        if ($tmp==='' || !is_file($tmp)) throw new RuntimeException('Временный файл загрузки не найден.');
        $raw=@file_get_contents($tmp);
        if ($raw===false) throw new RuntimeException('Не удалось прочитать TXT-файл.');
        $translationId=tl_custom_translation_import($transcriptId,$customName,$raw);
        json_response(['ok'=>true,'translation_id'=>$translationId]);
    }
    if ($action==='delete_translation') {
        tl_delete_translation((int)($_POST['id']??0));
        json_response(['ok'=>true]);
    }
    if ($action==='job_status') json_response(['ok'=>true,'job'=>tl_get_job((int)($_GET['id']??0))]);
    json_response(['ok'=>false,'error'=>'Неизвестное действие перевода.'],400);
} catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
