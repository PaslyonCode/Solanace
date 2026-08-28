<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/screenshot_worker_lib.php';

function tr_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_transcription_settings (
            id TINYINT UNSIGNED PRIMARY KEY,
            provider VARCHAR(50) NOT NULL DEFAULT 'groq',
            api_key TEXT NULL,
            python_path VARCHAR(1024) NULL,
            model VARCHAR(100) NOT NULL DEFAULT 'whisper-large-v3',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("INSERT IGNORE INTO app_transcription_settings (id, provider, api_key, model) VALUES (1, 'groq', NULL, 'whisper-large-v3')");
    $pythonColumn = $pdo->query("SHOW COLUMNS FROM app_transcription_settings LIKE 'python_path'")->fetch();
    if (!$pythonColumn) {
        $pdo->exec("ALTER TABLE app_transcription_settings ADD COLUMN python_path VARCHAR(1024) NULL AFTER api_key");
    }
    $modelColumn = $pdo->query("SHOW COLUMNS FROM app_transcription_settings LIKE 'model'")->fetch();
    if (!$modelColumn) {
        $pdo->exec("ALTER TABLE app_transcription_settings ADD COLUMN model VARCHAR(100) NOT NULL DEFAULT 'whisper-large-v3' AFTER provider");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS file_transcripts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            library_file_id BIGINT UNSIGNED NOT NULL,
            root_id INT UNSIGNED NOT NULL,
            source_hash CHAR(40) NOT NULL,
            audio_derivative_id BIGINT UNSIGNED NULL,
            text_derivative_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL,
            model VARCHAR(100) NULL,
            language VARCHAR(16) NULL,
            start_seconds DECIMAL(12,3) NULL,
            end_seconds DECIMAL(12,3) NULL,
            full_text MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_file_transcripts_file (library_file_id),
            INDEX idx_file_transcripts_source (root_id, source_hash),
            CONSTRAINT fk_file_transcripts_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_transcripts_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_transcripts_audio FOREIGN KEY (audio_derivative_id) REFERENCES file_derivatives(id) ON DELETE SET NULL,
            CONSTRAINT fk_file_transcripts_text FOREIGN KEY (text_derivative_id) REFERENCES file_derivatives(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS file_transcript_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transcript_id BIGINT UNSIGNED NOT NULL,
            sort_order INT UNSIGNED NOT NULL,
            start_seconds DECIMAL(12,3) NOT NULL,
            end_seconds DECIMAL(12,3) NOT NULL,
            segment_text TEXT NOT NULL,
            UNIQUE KEY uq_transcript_segment_order (transcript_id, sort_order),
            INDEX idx_transcript_segments_transcript (transcript_id),
            CONSTRAINT fk_transcript_segments_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function tr_provider_catalog(): array
{
    return [
        'groq' => [
            'id' => 'groq',
            'label' => 'Groq',
            'default_model' => 'whisper-large-v3',
            'models' => [
                ['id' => 'whisper-large-v3', 'label' => 'Whisper Large V3'],
                ['id' => 'whisper-large-v3-turbo', 'label' => 'Whisper Large V3 Turbo'],
            ],
        ],
    ];
}

function tr_get_settings(): array
{
    tr_ensure_schema();
    $stmt = db()->query('SELECT * FROM app_transcription_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch() ?: ['provider' => 'groq', 'api_key' => ''];
    $catalog = tr_provider_catalog();
    $provider = (string)($row['provider'] ?? 'groq');
    if (!isset($catalog[$provider])) $provider = 'groq';
    $model = trim((string)($row['model'] ?? ''));
    $validModels = array_column($catalog[$provider]['models'] ?? [], 'id');
    if (!in_array($model, $validModels, true)) $model = (string)$catalog[$provider]['default_model'];
    return [
        'provider' => $provider,
        'model' => $model,
        'api_key' => trim((string)($row['api_key'] ?? '')),
        'python_path' => trim((string)($row['python_path'] ?? '')),
    ];
}

function tr_public_settings(): array
{
    $settings = tr_get_settings();
    $key = $settings['api_key'];
    $hint = '';
    if ($key !== '') {
        $tail = strlen($key) > 4 ? substr($key, -4) : $key;
        $hint = '••••' . $tail;
    }
    return [
        'provider' => $settings['provider'],
        'model' => $settings['model'],
        'providers' => array_values(tr_provider_catalog()),
        'has_api_key' => $key !== '',
        'api_key_hint' => $hint,
        'python_path' => $settings['python_path'],
    ];
}

function tr_save_settings(string $provider, string $model, ?string $apiKey, ?string $pythonPath = null): array
{
    tr_ensure_schema();
    $provider = strtolower(trim($provider));
    $catalog = tr_provider_catalog();
    if (!isset($catalog[$provider])) throw new RuntimeException('Неизвестный сервис транскрибации.');
    $validModels = array_column($catalog[$provider]['models'] ?? [], 'id');
    if (!in_array($model, $validModels, true)) throw new RuntimeException('Неизвестная модель транскрибации.');
    $current = tr_get_settings();
    $apiKey = trim((string)$apiKey);
    if ($apiKey === '') $apiKey = $current['api_key'];
    $pythonPath = trim((string)$pythonPath);
    if ($pythonPath === '') $pythonPath = $current['python_path'];
    $stmt = db()->prepare('UPDATE app_transcription_settings SET provider = ?, model = ?, api_key = ?, python_path = ? WHERE id = 1');
    $stmt->execute([$provider, $model, $apiKey !== '' ? $apiKey : null, $pythonPath !== '' ? $pythonPath : null]);
    return tr_public_settings();
}

function tr_assert_ready(): array
{
    $settings = tr_get_settings();
    if ($settings['api_key'] === '') {
        throw new RuntimeException('API-ключ сервиса транскрибации не задан. Откройте «Действия → Настройки».');
    }
    return $settings;
}

function tr_format_time(float $seconds): string
{
    $whole = max(0, (int)floor($seconds));
    $h = intdiv($whole, 3600);
    $m = intdiv($whole % 3600, 60);
    $s = $whole % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function tr_provider_file(string $provider): string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $provider)) throw new RuntimeException('Некорректный идентификатор сервиса транскрибации.');
    $file = __DIR__ . '/transcription/providers/' . $provider . '.php';
    if (!is_file($file)) throw new RuntimeException('Адаптер сервиса транскрибации не найден: ' . $provider);
    return $file;
}

function tr_provider_config(string $provider): array
{
    require_once tr_provider_file($provider);
    $fn = 'tr_provider_' . $provider . '_config';
    if (!function_exists($fn)) throw new RuntimeException('Адаптер сервиса не предоставляет конфигурацию.');
    return $fn();
}

function tr_provider_transcribe_one(string $provider, string $audioPath, string $apiKey, array $options = []): array
{
    require_once tr_provider_file($provider);
    $fn = 'tr_provider_' . $provider . '_transcribe';
    if (!function_exists($fn)) throw new RuntimeException('Адаптер сервиса не поддерживает транскрибацию.');
    return $fn($audioPath, $apiKey, $options);
}

function tr_run_ffmpeg(array $args, int $timeoutSeconds = 3600): void
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Не удалось запустить FFmpeg для подготовки аудио к транскрибации.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stderr = '';
    $started = microtime(true);
    $pid = 0;
    while (true) {
        $status = proc_get_status($proc);
        if ($pid <= 0) $pid = (int)($status['pid'] ?? 0);
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        stream_get_contents($pipes[1]);
        if (!$status['running']) break;
        if (microtime(true) - $started > $timeoutSeconds) {
            if (PHP_OS_FAMILY === 'Windows' && $pid > 0) @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
            else @proc_terminate($proc, 9);
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            @proc_close($proc);
            throw new RuntimeException('Подготовка аудио для транскрибации превысила лимит времени.');
        }
        usleep(100000);
    }
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
    $exitFromStatus = isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
    $code = proc_close($proc);
    if ($code === -1 && $exitFromStatus >= 0) $code = $exitFromStatus;
    if ($code !== 0) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $stderr) ?: [])));
        throw new RuntimeException('FFmpeg: ' . implode(' | ', array_slice($lines, -6)));
    }
}

function tr_probe_duration(string $path): ?float
{
    $probe = sw_ffprobe_path();
    $cmd = escapeshellarg($probe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path);
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code !== 0 || !$out) return null;
    $v = (float)trim((string)$out[0]);
    return $v > 0 ? $v : null;
}

function tr_make_chunks_if_needed(string $audioPath, int $maxBytes): array
{
    $size = (int)(filesize($audioPath) ?: 0);
    if ($size > 0 && $size <= $maxBytes) {
        return [['path' => $audioPath, 'offset' => 0.0, 'temporary' => false]];
    }
    $duration = tr_probe_duration($audioPath);
    if ($duration === null) throw new RuntimeException('Аудиофайл превышает лимит сервиса, но не удалось определить его длительность для разбиения.');

    $tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'videocat_transcript_' . bin2hex(random_bytes(6));
    if (!@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) throw new RuntimeException('Не удалось создать временную папку для частей аудио.');
    $ffmpeg = sw_ffmpeg_path();
    $chunks = [];
    $chunkLength = 600.0; // 10 минут: даже несжатый mono 16 kHz PCM около 19.2 МБ.
    $index = 0;
    for ($offset = 0.0; $offset < $duration - 0.001; $offset += $chunkLength) {
        $index++;
        $length = min($chunkLength, $duration - $offset);
        $chunk = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%03d.flac', $index);
        tr_run_ffmpeg([
            $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', sprintf('%.3f', $offset), '-i', $audioPath,
            '-t', sprintf('%.3f', $length), '-vn', '-ac', '1', '-ar', '16000',
            '-c:a', 'flac', '-compression_level', '8', $chunk,
        ], 3600);
        if (!is_file($chunk) || filesize($chunk) < 1) throw new RuntimeException('Не удалось создать часть аудио для транскрибации.');
        if ((int)filesize($chunk) > $maxBytes) {
            throw new RuntimeException('Даже 10-минутная FLAC-часть превышает лимит загрузки сервиса. Используйте MP3 или меньший интервал.');
        }
        $chunks[] = ['path' => $chunk, 'offset' => $offset, 'temporary' => true, 'temp_dir' => $tempDir];
    }
    return $chunks;
}

function tr_cleanup_chunks(array $chunks): void
{
    $dirs = [];
    foreach ($chunks as $chunk) {
        if (!empty($chunk['temporary']) && is_file((string)$chunk['path'])) @unlink((string)$chunk['path']);
        if (!empty($chunk['temp_dir'])) $dirs[(string)$chunk['temp_dir']] = true;
    }
    foreach (array_keys($dirs) as $dir) @rmdir($dir);
}

function tr_normalize_detected_language(string $language): string
{
    $language = strtolower(trim($language));
    if ($language === 'russian' || str_starts_with($language, 'ru-')) return 'ru';
    if ($language === 'english' || str_starts_with($language, 'en-')) return 'en';
    return $language;
}

function tr_transcribe_audio(string $audioPath, array $settings, string $language = 'auto'): array
{
    $language = strtolower(trim($language));
    if (!in_array($language, ['auto', 'ru', 'en'], true)) {
        throw new RuntimeException('Некорректный язык транскрибации.');
    }
    $provider = (string)$settings['provider'];
    $config = tr_provider_config($provider);
    $maxBytes = (int)($config['max_upload_bytes'] ?? (24 * 1024 * 1024));
    $chunks = tr_make_chunks_if_needed($audioPath, $maxBytes);
    $segments = [];
    $fullTextParts = [];
    $model = (string)($settings['model'] ?? ($config['default_model'] ?? ''));
    $detectedLanguage = '';
    $providerOptions = [];
    if ($language !== 'auto') $providerOptions['language'] = $language;
    if (!empty($settings['python_path'])) $providerOptions['python_path'] = (string)$settings['python_path'];
    if (!empty($settings['model'])) $providerOptions['model'] = (string)$settings['model'];
    try {
        foreach ($chunks as $chunk) {
            $result = tr_provider_transcribe_one($provider, (string)$chunk['path'], (string)$settings['api_key'], $providerOptions);
            if (!empty($result['model'])) $model = (string)$result['model'];
            if ($detectedLanguage === '' && !empty($result['language'])) $detectedLanguage = tr_normalize_detected_language((string)$result['language']);
            $offset = (float)($chunk['offset'] ?? 0);
            foreach (($result['segments'] ?? []) as $seg) {
                $text = trim((string)($seg['text'] ?? ''));
                if ($text === '') continue;
                $segments[] = [
                    'start' => $offset + (float)($seg['start'] ?? 0),
                    'end' => $offset + (float)($seg['end'] ?? ($seg['start'] ?? 0)),
                    'text' => $text,
                ];
            }
            $piece = trim((string)($result['text'] ?? ''));
            if ($piece !== '') $fullTextParts[] = $piece;
        }
    } finally {
        tr_cleanup_chunks($chunks);
    }
    if (!$segments && !$fullTextParts) throw new RuntimeException('Сервис вернул пустой транскрипт.');
    $fullText = trim(implode("\n", $fullTextParts));
    if ($fullText === '' && $segments) $fullText = implode(' ', array_column($segments, 'text'));
    return [
        'provider' => $provider,
        'model' => $model,
        'language' => $language === 'auto' ? $detectedLanguage : $language,
        'text' => $fullText,
        'segments' => $segments,
    ];
}

function tr_insert_transcript(array $file, int $audioDerivativeId, int $textDerivativeId, array $result, ?float $start, ?float $end): int
{
    tr_ensure_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO file_transcripts
                (library_file_id, root_id, source_hash, audio_derivative_id, text_derivative_id, provider, model, language, start_seconds, end_seconds, full_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$file['id'], (int)$file['root_id'], (string)$file['file_hash'],
            $audioDerivativeId, $textDerivativeId,
            (string)$result['provider'], (string)($result['model'] ?? ''), (string)($result['language'] ?? ''),
            $start, $end, (string)$result['text'],
        ]);
        $transcriptId = (int)$pdo->lastInsertId();
        $segStmt = $pdo->prepare(
            'INSERT INTO file_transcript_segments (transcript_id, sort_order, start_seconds, end_seconds, segment_text)
             VALUES (?, ?, ?, ?, ?)'
        );
        $baseOffset = $start ?? 0.0;
        $order = 0;
        foreach (($result['segments'] ?? []) as $segment) {
            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') continue;
            $segStmt->execute([
                $transcriptId, $order++,
                $baseOffset + (float)($segment['start'] ?? 0),
                $baseOffset + (float)($segment['end'] ?? ($segment['start'] ?? 0)),
                $text,
            ]);
        }
        $pdo->commit();
        return $transcriptId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tr_list_for_file(int $libraryFileId): array
{
    tr_ensure_schema();
    $stmt = db()->prepare(
        'SELECT ft.*, fd.download_name, fd.created_at AS derivative_created_at,
                (SELECT COUNT(*) FROM file_transcript_segments s WHERE s.transcript_id = ft.id) AS segment_count
         FROM file_transcripts ft
         INNER JOIN file_derivatives fd ON fd.id = ft.text_derivative_id
         WHERE ft.library_file_id = ?
         ORDER BY ft.created_at DESC, ft.id DESC'
    );
    $stmt->execute([$libraryFileId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'text_derivative_id' => (int)$row['text_derivative_id'],
            'audio_derivative_id' => $row['audio_derivative_id'] ? (int)$row['audio_derivative_id'] : null,
            'download_name' => (string)$row['download_name'],
            'download_url' => 'derived_media.php?id=' . (int)$row['text_derivative_id'] . '&download=1',
            'provider' => (string)$row['provider'],
            'model' => (string)($row['model'] ?? ''),
            'language' => (string)($row['language'] ?? ''),
            'translations' => function_exists('tl_list_for_transcript') ? tl_list_for_transcript((int)$row['id']) : [],
            'translation_job' => function_exists('tl_active_job_for_transcript') ? tl_active_job_for_transcript((int)$row['id']) : null,
            'start_seconds' => $row['start_seconds'] !== null ? (float)$row['start_seconds'] : null,
            'end_seconds' => $row['end_seconds'] !== null ? (float)$row['end_seconds'] : null,
            'segment_count' => (int)$row['segment_count'],
            'preview' => mb_substr(trim((string)$row['full_text']), 0, 220, 'UTF-8'),
            'created_at' => $row['created_at'],
        ];
    }
    return $out;
}

function tr_get_transcript(int $id): array
{
    tr_ensure_schema();
    $stmt = db()->prepare(
        'SELECT ft.*, fd.download_name, lf.file_path, lf.file_name, fc.custom_title
         FROM file_transcripts ft
         INNER JOIN file_derivatives fd ON fd.id = ft.text_derivative_id
         INNER JOIN library_files lf ON lf.id = ft.library_file_id
         LEFT JOIN file_cards fc ON fc.file_hash = lf.file_hash
         WHERE ft.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Транскрипт не найден.');
    $segStmt = db()->prepare('SELECT id, sort_order, start_seconds, end_seconds, segment_text FROM file_transcript_segments WHERE transcript_id = ? ORDER BY start_seconds, id');
    $segStmt->execute([$id]);
    $segments = array_map(static function (array $seg): array {
        return [
            'id' => (int)$seg['id'],
            'sort_order' => (int)$seg['sort_order'],
            'start' => (float)$seg['start_seconds'],
            'end' => (float)$seg['end_seconds'],
            'text' => (string)$seg['segment_text'],
        ];
    }, $segStmt->fetchAll());
    return [
        'id' => (int)$row['id'],
        'title' => trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : (string)$row['file_name'],
        'token' => base64url_encode((string)$row['file_path']),
        'download_name' => (string)$row['download_name'],
        'download_url' => 'derived_media.php?id=' . (int)$row['text_derivative_id'] . '&download=1',
        'provider' => (string)$row['provider'],
        'model' => (string)($row['model'] ?? ''),
        'language' => (string)($row['language'] ?? ''),
        'translations' => function_exists('tl_translation_payloads') ? tl_translation_payloads((int)$row['id']) : [],
        'translation_languages' => function_exists('tl_language_catalog') ? array_values(tl_language_catalog()) : [],
        'segments' => $segments,
        'full_text' => (string)$row['full_text'],
    ];
}

function tr_rebuild_transcript_text(int $transcriptId): void
{
    tr_ensure_schema();
    $stmt = db()->prepare('SELECT start_seconds,segment_text FROM file_transcript_segments WHERE transcript_id=? ORDER BY start_seconds,id');
    $stmt->execute([$transcriptId]);
    $rows = $stmt->fetchAll();
    $full = implode("\n", array_map(static fn(array $x): string => trim((string)$x['segment_text']), $rows));
    db()->prepare('UPDATE file_transcripts SET full_text=? WHERE id=?')->execute([$full,$transcriptId]);

    // Keep the original downloadable TXT in sync with manual edits.
    $q = db()->prepare(
        'SELECT fd.relative_path, lr.root_path
         FROM file_transcripts ft
         INNER JOIN file_derivatives fd ON fd.id=ft.text_derivative_id
         INNER JOIN library_roots lr ON lr.id=ft.root_id
         WHERE ft.id=? LIMIT 1'
    );
    $q->execute([$transcriptId]);
    $file = $q->fetch();
    if ($file) {
        $path = rtrim((string)$file['root_path'], "\\/") . DIRECTORY_SEPARATOR . VIDEO_SCREENSHOT_DIRNAME . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file['relative_path']);
        $lines = [];
        foreach ($rows as $row) $lines[] = '[' . tr_format_time((float)$row['start_seconds']) . '] ' . trim((string)$row['segment_text']);
        @file_put_contents($path, ($lines ? implode("\r\n\r\n", $lines) . "\r\n" : ''));
    }
}

function tr_update_segment(int $transcriptId, int $segmentId, string $text): void
{
    tr_ensure_schema();
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Текст фрагмента не может быть пустым. Используйте удаление фрагмента.');
    $stmt = db()->prepare('UPDATE file_transcript_segments SET segment_text=? WHERE id=? AND transcript_id=?');
    $stmt->execute([$text,$segmentId,$transcriptId]);
    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM file_transcript_segments WHERE id=? AND transcript_id=?');
        $check->execute([$segmentId,$transcriptId]);
        if (!$check->fetchColumn()) throw new RuntimeException('Фрагмент транскрипта не найден.');
    }
    tr_rebuild_transcript_text($transcriptId);
}

function tr_delete_segment(int $transcriptId, int $segmentId): void
{
    tr_ensure_schema();
    $stmt = db()->prepare('DELETE FROM file_transcript_segments WHERE id=? AND transcript_id=?');
    $stmt->execute([$segmentId,$transcriptId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('Фрагмент транскрипта не найден.');
    tr_rebuild_transcript_text($transcriptId);
}

function tr_parse_editor_time(string $value): float
{
    $value = trim(str_replace(',', '.', $value));
    $parts = explode(':', $value);
    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) return max(0.0, (float)$parts[0]*60.0 + (float)$parts[1]);
    if (count($parts) === 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) return max(0.0, (float)$parts[0]*3600.0 + (float)$parts[1]*60.0 + (float)$parts[2]);
    throw new RuntimeException('Тайм-код должен иметь вид [hh:mm:ss] или [mm:ss].');
}

function tr_parse_editor_fragment(string $input): array
{
    $input = trim(str_replace(["\r\n","\r"], "\n", $input));
    if (!preg_match('/^\s*\[\s*([^\]]+)\s*\]\s*(.*)$/su', $input, $m)) throw new RuntimeException('Введите фрагмент в виде [hh:mm:ss] текст.');
    $text = trim((string)$m[2]);
    if ($text === '') throw new RuntimeException('Введите текст фрагмента.');
    return ['start'=>tr_parse_editor_time((string)$m[1]), 'text'=>$text];
}

function tr_add_segment(int $transcriptId, string $input): int
{
    tr_ensure_schema();
    $seg = tr_parse_editor_fragment($input);
    $exists = db()->prepare('SELECT id,end_seconds FROM file_transcripts WHERE id=? LIMIT 1');
    $exists->execute([$transcriptId]);
    $tr = $exists->fetch();
    if (!$tr) throw new RuntimeException('Транскрипт не найден.');
    $q = db()->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM file_transcript_segments WHERE transcript_id=?');
    $q->execute([$transcriptId]);
    $order = (int)$q->fetchColumn();
    $next = db()->prepare('SELECT MIN(start_seconds) FROM file_transcript_segments WHERE transcript_id=? AND start_seconds>?');
    $next->execute([$transcriptId,(float)$seg['start']]);
    $nextStart = $next->fetchColumn();
    $fallbackEnd = $tr['end_seconds'] !== null ? (float)$tr['end_seconds'] : (float)$seg['start'];
    $end = $nextStart !== null ? (float)$nextStart : max((float)$seg['start'],$fallbackEnd);
    $ins = db()->prepare('INSERT INTO file_transcript_segments (transcript_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
    $ins->execute([$transcriptId,$order,(float)$seg['start'],$end,(string)$seg['text']]);
    $id = (int)db()->lastInsertId();
    tr_rebuild_transcript_text($transcriptId);
    return $id;
}

