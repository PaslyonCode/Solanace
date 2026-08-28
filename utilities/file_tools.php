<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/file_tools_lib.php';

auth_require_json();
ob_start();
ft_ensure_schema();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'status') {
        $file = ft_find_cached_file_by_token((string)($_GET['token'] ?? ''));
        json_response(['ok' => true, 'tools' => ft_status_for_file($file)]);
    }

    if ($action === 'resolve_view') {
        $file = ft_find_cached_file_by_token((string)($_GET['token'] ?? ''));
        json_response(['ok' => true] + ft_resolve_view_url($file));
    }

    if ($action === 'start_operations') {
        $file = ft_find_cached_file_by_token((string)($_POST['token'] ?? ''));
        $doClip = filter_var($_POST['do_clip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $doAudio = filter_var($_POST['do_audio'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $doTranscript = filter_var($_POST['do_transcript'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$doClip && !$doAudio && !$doTranscript) throw new RuntimeException('Выберите хотя бы одну операцию.');

        $start = ft_parse_time($_POST['start'] ?? '');
        $end = ft_parse_time($_POST['end'] ?? '');
        if ($doClip) ft_validate_interval($start, $end, false);
        else ft_validate_interval($start, $end, true);

        $format = 'mp3';
        $bitrate = 64;
        $language = 'auto';
        if ($doAudio || $doTranscript) {
            $format = strtolower(trim((string)($_POST['format'] ?? 'mp3')));
            if (!in_array($format, ['mp3','flac'], true)) throw new RuntimeException('Выберите MP3 или FLAC.');
            $bitrate = (int)($_POST['bitrate'] ?? 64);
            if (!in_array($bitrate, [64,96,192], true)) throw new RuntimeException('Выберите битрейт 64, 96 или 192 кбит/с.');
            $language = strtolower(trim((string)($_POST['language'] ?? 'auto')));
            if (!in_array($language, ['auto','ru','en'], true)) throw new RuntimeException('Выберите язык: автоматически, русский или английский.');
            if ($doTranscript) tr_assert_ready();
        }

        $jobs = [];
        if ($doClip) $jobs[] = ft_create_job($file, 'clip', ['start'=>$start,'end'=>$end]);
        if ($doAudio || $doTranscript) {
            $type = $doTranscript ? 'transcript' : 'audio';
            $jobs[] = ft_create_job($file, $type, [
                'start'=>$start,'end'=>$end,'format'=>$format,'bitrate'=>$bitrate,'language'=>$language,
            ]);
        }
        json_response(['ok'=>true,'jobs'=>array_map('ft_job_payload',$jobs)]);
    }

    if ($action === 'start_audio' || $action === 'start_transcript' || $action === 'start_clip') {
        $file = ft_find_cached_file_by_token((string)($_POST['token'] ?? ''));
        $start = ft_parse_time($_POST['start'] ?? '');
        $end = ft_parse_time($_POST['end'] ?? '');
        $isAudioLike = $action === 'start_audio' || $action === 'start_transcript';
        ft_validate_interval($start, $end, $isAudioLike);
        if ($action === 'start_clip') {
            $job = ft_create_job($file, 'clip', ['start' => $start, 'end' => $end]);
            json_response(['ok' => true, 'job' => ft_job_payload($job)]);
        }
        $format = strtolower(trim((string)($_POST['format'] ?? 'mp3')));
        if (!in_array($format, ['mp3', 'flac'], true)) throw new RuntimeException('Выберите MP3 или FLAC.');
        $bitrate = (int)($_POST['bitrate'] ?? 64);
        if (!in_array($bitrate, [64, 96, 192], true)) throw new RuntimeException('Выберите битрейт 64, 96 или 192 кбит/с.');
        $type = $action === 'start_transcript' ? 'transcript' : 'audio';
        $language = strtolower(trim((string)($_POST['language'] ?? 'auto')));
        if (!in_array($language, ['auto', 'ru', 'en'], true)) {
            throw new RuntimeException('Выберите язык: автоматически, русский или английский.');
        }
        if ($type === 'transcript') tr_assert_ready();
        $job = ft_create_job($file, $type, [
            'start' => $start,
            'end' => $end,
            'format' => $format,
            'bitrate' => $bitrate,
            'language' => $language,
        ]);
        json_response(['ok' => true, 'job' => ft_job_payload($job)]);
    }

    if ($action === 'start_convert') {
        $file = ft_find_cached_file_by_token((string)($_POST['token'] ?? ''));
        if (ft_browser_playable_extension(ft_file_extension((string)$file['file_name']))) {
            throw new RuntimeException('Этот формат уже считается воспроизводимым браузером.');
        }
        $job = ft_create_job($file, 'convert', []);
        json_response(['ok' => true, 'job' => ft_job_payload($job)]);
    }

    if ($action === 'job_status') {
        $job = ft_get_job((int)($_GET['id'] ?? 0));
        json_response(['ok' => true, 'job' => ft_job_payload($job)]);
    }

    if ($action === 'delete_derivative') {
        ft_delete_derivative((int)($_POST['id'] ?? 0));
        json_response(['ok' => true]);
    }

    if ($action === 'promote_clip') {
        $result = ft_promote_clip((int)($_POST['id'] ?? 0));
        json_response(['ok'=>true,'file'=>$result]);
    }

    if ($action === 'finalize_conversion') {
        $result = ft_finalize_conversion((int)($_POST['id'] ?? 0));
        json_response(['ok' => true, 'file' => $result]);
    }

    json_response(['ok' => false, 'error' => 'Неизвестное действие утилиты.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
