<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/file_tools_lib.php';
require_once dirname(__DIR__) . '/transcription_lib.php';
require_once dirname(__DIR__) . '/translation_lib.php';

auth_require_json();
ob_start();
ft_ensure_schema();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'settings') {
        json_response(['ok' => true, 'settings' => tr_public_settings()]);
    }
    if ($action === 'save_settings') {
        $settings = tr_save_settings((string)($_POST['provider'] ?? 'groq'), (string)($_POST['model'] ?? 'whisper-large-v3'), (string)($_POST['api_key'] ?? ''), (string)($_POST['python_path'] ?? ''));
        json_response(['ok' => true, 'settings' => $settings]);
    }
    if ($action === 'get') {
        json_response(['ok' => true, 'transcript' => tr_get_transcript((int)($_GET['id'] ?? 0))]);
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT text_derivative_id FROM file_transcripts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $derivativeId = (int)($stmt->fetchColumn() ?: 0);
        if ($derivativeId) ft_delete_derivative($derivativeId);
        json_response(['ok' => true]);
    }
    if ($action === 'segment_update' || $action === 'segment_delete' || $action === 'segment_add') {
        $transcriptId = (int)($_POST['transcript_id'] ?? 0);
        $version = (string)($_POST['version'] ?? 'original');
        if ($transcriptId <= 0) throw new RuntimeException('Транскрипт не указан.');
        if ($version === 'original') {
            if ($action === 'segment_update') tr_update_segment($transcriptId, (int)($_POST['segment_id'] ?? 0), (string)($_POST['text'] ?? ''));
            elseif ($action === 'segment_delete') tr_delete_segment($transcriptId, (int)($_POST['segment_id'] ?? 0));
            else tr_add_segment($transcriptId, (string)($_POST['input'] ?? ''));
        } elseif (preg_match('/^translation:(\d+)$/', $version, $m)) {
            $translationId = (int)$m[1];
            $check = db()->prepare('SELECT id FROM file_transcript_translations WHERE id=? AND transcript_id=? LIMIT 1');
            $check->execute([$translationId,$transcriptId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Перевод не принадлежит этому транскрипту.');
            if ($action === 'segment_update') tl_update_translation_segment($translationId, (int)($_POST['segment_id'] ?? 0), (string)($_POST['text'] ?? ''));
            elseif ($action === 'segment_delete') tl_delete_translation_segment($translationId, (int)($_POST['segment_id'] ?? 0));
            else tl_add_translation_segment($translationId, (string)($_POST['input'] ?? ''));
        } else {
            throw new RuntimeException('Неизвестная версия транскрипта.');
        }
        json_response(['ok'=>true,'transcript'=>tr_get_transcript($transcriptId)]);
    }
    json_response(['ok' => false, 'error' => 'Неизвестное действие транскрибации.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
