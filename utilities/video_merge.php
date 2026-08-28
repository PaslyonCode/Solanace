<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/video_merge_lib.php';

auth_require_json();
ob_start();
vm_ensure_schema();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'start') {
        $root = vm_root_by_path((string)($_POST['root'] ?? ''));
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items)) throw new RuntimeException('Некорректный список видео.');
        $paths = [];
        foreach ($items as $item) {
            if (is_string($item)) $paths[] = $item;
            elseif (is_array($item) && isset($item['path'])) $paths[] = (string)$item['path'];
        }
        $job = vm_create_job($root, $paths, (string)($_POST['output_name'] ?? ''), [
            'mode' => $_POST['mode'] ?? 'auto',
            'resolution' => $_POST['resolution'] ?? 'auto',
            'aspect' => $_POST['aspect'] ?? 'fit',
            'quality' => $_POST['quality'] ?? 'normal',
        ]);
        json_response(['ok' => true, 'job' => vm_job_payload($job)]);
    }

    if ($action === 'job_status') {
        json_response(['ok' => true, 'job' => vm_job_payload(vm_get_job((int)($_GET['id'] ?? 0)))]);
    }

    if ($action === 'active_jobs') {
        $root = vm_root_by_path((string)($_GET['root'] ?? ''));
        json_response(['ok' => true, 'jobs' => vm_active_jobs_for_root((int)$root['id'])]);
    }

    if ($action === 'card_info') {
        json_response(['ok' => true, 'merge' => vm_card_info((string)($_GET['token'] ?? ''))]);
    }

    json_response(['ok' => false, 'error' => 'Неизвестное действие утилиты склейки.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
