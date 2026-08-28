<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/screenshot_worker_lib.php';
require_once __DIR__ . '/transcription_lib.php';

function tl_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_translation_settings (
            id TINYINT UNSIGNED PRIMARY KEY,
            provider VARCHAR(50) NOT NULL DEFAULT 'groq',
            model VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-20b',
            api_key TEXT NULL,
            python_path VARCHAR(1024) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("INSERT IGNORE INTO app_translation_settings (id, provider, model, api_key) VALUES (1, 'groq', 'openai/gpt-oss-20b', NULL)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS file_transcript_translations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transcript_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL,
            model VARCHAR(100) NULL,
            source_language VARCHAR(16) NULL,
            target_language VARCHAR(16) NOT NULL,
            translation_type VARCHAR(20) NOT NULL DEFAULT 'machine',
            custom_name VARCHAR(190) NULL,
            variant_key VARCHAR(220) NOT NULL,
            full_text MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_transcript_translation_variant (transcript_id, variant_key),
            INDEX idx_transcript_translation_transcript (transcript_id),
            CONSTRAINT fk_transcript_translation_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS file_transcript_translation_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            translation_id BIGINT UNSIGNED NOT NULL,
            sort_order INT UNSIGNED NOT NULL,
            start_seconds DECIMAL(12,3) NULL,
            end_seconds DECIMAL(12,3) NULL,
            segment_text TEXT NOT NULL,
            UNIQUE KEY uq_translation_segment_order (translation_id, sort_order),
            INDEX idx_translation_segments_translation (translation_id),
            CONSTRAINT fk_translation_segments_translation FOREIGN KEY (translation_id) REFERENCES file_transcript_translations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Schema upgrades for manual translations and editable segments.
    foreach ([
        'translation_type' => "ALTER TABLE file_transcript_translations ADD COLUMN translation_type VARCHAR(20) NOT NULL DEFAULT 'machine' AFTER target_language",
        'custom_name' => "ALTER TABLE file_transcript_translations ADD COLUMN custom_name VARCHAR(190) NULL AFTER translation_type",
        'variant_key' => "ALTER TABLE file_transcript_translations ADD COLUMN variant_key VARCHAR(220) NULL AFTER custom_name",
    ] as $column => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM file_transcript_translations LIKE " . $pdo->quote($column))->fetch();
        if (!$check) $pdo->exec($sql);
    }
    $pdo->exec("UPDATE file_transcript_translations SET translation_type='machine' WHERE translation_type IS NULL OR translation_type=''");
    $pdo->exec("UPDATE file_transcript_translations SET variant_key=CONCAT('lang:', target_language) WHERE variant_key IS NULL OR variant_key=''");
    $legacyIndex = $pdo->query("SHOW INDEX FROM file_transcript_translations WHERE Key_name='uq_transcript_translation_language'")->fetch();
    if ($legacyIndex) $pdo->exec("ALTER TABLE file_transcript_translations DROP INDEX uq_transcript_translation_language");
    $variantIndex = $pdo->query("SHOW INDEX FROM file_transcript_translations WHERE Key_name='uq_transcript_translation_variant'")->fetch();
    if (!$variantIndex) $pdo->exec("ALTER TABLE file_transcript_translations ADD UNIQUE KEY uq_transcript_translation_variant (transcript_id, variant_key)");
    $variantColumn = $pdo->query("SHOW COLUMNS FROM file_transcript_translations LIKE 'variant_key'")->fetch();
    if ($variantColumn && strtoupper((string)($variantColumn['Null'] ?? 'YES')) === 'YES') {
        $pdo->exec("ALTER TABLE file_transcript_translations MODIFY variant_key VARCHAR(220) NOT NULL");
    }

    foreach ([
        'start_seconds' => "ALTER TABLE file_transcript_translation_segments ADD COLUMN start_seconds DECIMAL(12,3) NULL AFTER sort_order",
        'end_seconds' => "ALTER TABLE file_transcript_translation_segments ADD COLUMN end_seconds DECIMAL(12,3) NULL AFTER start_seconds",
    ] as $column => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM file_transcript_translation_segments LIKE " . $pdo->quote($column))->fetch();
        if (!$check) $pdo->exec($sql);
    }
    // Existing machine translations inherit the time marks of their source transcript once.
    $pdo->exec("UPDATE file_transcript_translation_segments ts
        INNER JOIN file_transcript_translations t ON t.id=ts.translation_id
        INNER JOIN file_transcript_segments s ON s.transcript_id=t.transcript_id AND s.sort_order=ts.sort_order
        SET ts.start_seconds=COALESCE(ts.start_seconds,s.start_seconds), ts.end_seconds=COALESCE(ts.end_seconds,s.end_seconds)
        WHERE ts.start_seconds IS NULL OR ts.end_seconds IS NULL");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transcript_translation_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transcript_id BIGINT UNSIGNED NOT NULL,
            target_language VARCHAR(16) NOT NULL,
            provider VARCHAR(50) NOT NULL,
            model VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            translation_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            INDEX idx_translation_jobs_transcript (transcript_id, status),
            CONSTRAINT fk_translation_job_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE,
            CONSTRAINT fk_translation_job_translation FOREIGN KEY (translation_id) REFERENCES file_transcript_translations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function tl_language_catalog(): array
{
    // Add languages here later; UI and providers consume this catalog dynamically.
    return [
        'ru' => ['id' => 'ru', 'label' => 'Русский', 'english_name' => 'Russian'],
        'en' => ['id' => 'en', 'label' => 'Английский', 'english_name' => 'English'],
    ];
}

function tl_normalize_language(string $language): string
{
    $language = strtolower(trim($language));
    if ($language === 'russian') return 'ru';
    if ($language === 'english') return 'en';
    if (str_starts_with($language, 'ru-')) return 'ru';
    if (str_starts_with($language, 'en-')) return 'en';
    return isset(tl_language_catalog()[$language]) ? $language : '';
}

function tl_provider_catalog(): array
{
    return [
        'groq' => [
            'id' => 'groq',
            'label' => 'Groq',
            'default_model' => 'openai/gpt-oss-20b',
            'models' => [
                ['id' => 'openai/gpt-oss-20b', 'label' => 'GPT-OSS 20B'],
                ['id' => 'openai/gpt-oss-120b', 'label' => 'GPT-OSS 120B'],
            ],
        ],
    ];
}

function tl_get_settings(): array
{
    tl_ensure_schema();
    $row = db()->query('SELECT * FROM app_translation_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
    $catalog = tl_provider_catalog();
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

function tl_effective_settings(): array
{
    $settings = tl_get_settings();
    // Groq can reuse the already configured transcription credentials.
    if ($settings['provider'] === 'groq') {
        $tr = tr_get_settings();
        if ($settings['api_key'] === '' && ($tr['provider'] ?? '') === 'groq') $settings['api_key'] = (string)($tr['api_key'] ?? '');
        if ($settings['python_path'] === '') $settings['python_path'] = (string)($tr['python_path'] ?? '');
    }
    return $settings;
}

function tl_public_settings(): array
{
    $raw = tl_get_settings();
    $effective = tl_effective_settings();
    $key = $raw['api_key'];
    $hint = '';
    if ($key !== '') $hint = '••••' . (strlen($key) > 4 ? substr($key, -4) : $key);
    return [
        'provider' => $raw['provider'],
        'model' => $raw['model'],
        'providers' => array_values(tl_provider_catalog()),
        'languages' => array_values(tl_language_catalog()),
        'has_api_key' => $key !== '',
        'uses_transcription_key' => $key === '' && $effective['api_key'] !== '',
        'api_key_hint' => $hint,
        'python_path' => $raw['python_path'],
        'uses_transcription_python' => $raw['python_path'] === '' && $effective['python_path'] !== '',
    ];
}

function tl_save_settings(string $provider, string $model, ?string $apiKey, ?string $pythonPath): array
{
    tl_ensure_schema();
    $provider = strtolower(trim($provider));
    $catalog = tl_provider_catalog();
    if (!isset($catalog[$provider])) throw new RuntimeException('Неизвестный сервис перевода.');
    $validModels = array_column($catalog[$provider]['models'] ?? [], 'id');
    if (!in_array($model, $validModels, true)) throw new RuntimeException('Неизвестная модель перевода.');
    $current = tl_get_settings();
    $apiKey = trim((string)$apiKey);
    if ($apiKey === '') $apiKey = $current['api_key'];
    $pythonPath = trim((string)$pythonPath);
    if ($pythonPath === '') $pythonPath = $current['python_path'];
    $stmt = db()->prepare('UPDATE app_translation_settings SET provider=?, model=?, api_key=?, python_path=? WHERE id=1');
    $stmt->execute([$provider, $model, $apiKey !== '' ? $apiKey : null, $pythonPath !== '' ? $pythonPath : null]);
    return tl_public_settings();
}

function tl_assert_ready(): array
{
    $settings = tl_effective_settings();
    if ($settings['api_key'] === '') throw new RuntimeException('API-ключ сервиса перевода не задан. Откройте «Действия → Настройки».');
    return $settings;
}

function tl_provider_file(string $provider): string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $provider)) throw new RuntimeException('Некорректный идентификатор сервиса перевода.');
    $file = __DIR__ . '/translation/providers/' . $provider . '.php';
    if (!is_file($file)) throw new RuntimeException('Адаптер сервиса перевода не найден: ' . $provider);
    return $file;
}

function tl_provider_translate_batch(string $provider, array $items, string $sourceLanguage, string $targetLanguage, array $settings): array
{
    require_once tl_provider_file($provider);
    $fn = 'tl_provider_' . $provider . '_translate_batch';
    if (!function_exists($fn)) throw new RuntimeException('Адаптер сервиса не поддерживает перевод.');
    return $fn($items, $sourceLanguage, $targetLanguage, $settings);
}

function tl_transcript_row(int $transcriptId): array
{
    tl_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM file_transcripts WHERE id=? LIMIT 1');
    $stmt->execute([$transcriptId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Транскрипт не найден.');
    return $row;
}

function tl_source_segments(int $transcriptId): array
{
    $stmt = db()->prepare('SELECT id,sort_order,start_seconds,end_seconds,segment_text FROM file_transcript_segments WHERE transcript_id=? ORDER BY start_seconds,id');
    $stmt->execute([$transcriptId]);
    $segments = [];
    foreach ($stmt->fetchAll() as $row) {
        $text = trim((string)$row['segment_text']);
        if ($text === '') continue;
        $segments[] = [
            'id' => (int)$row['sort_order'],
            'segment_id' => (int)$row['id'],
            'start' => (float)$row['start_seconds'],
            'end' => (float)$row['end_seconds'],
            'text' => $text,
        ];
    }
    if (!$segments) throw new RuntimeException('В транскрипте нет сегментов для перевода.');
    return $segments;
}

function tl_existing_translation(int $transcriptId, string $targetLanguage): ?array
{
    tl_ensure_schema();
    $stmt = db()->prepare("SELECT * FROM file_transcript_translations WHERE transcript_id=? AND translation_type='machine' AND target_language=? LIMIT 1");
    $stmt->execute([$transcriptId, $targetLanguage]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tl_create_job(int $transcriptId, string $targetLanguage): array
{
    tl_ensure_schema();
    $targetLanguage = tl_normalize_language($targetLanguage);
    if ($targetLanguage === '') throw new RuntimeException('Неизвестный язык перевода.');
    $transcript = tl_transcript_row($transcriptId);
    $sourceLanguage = tl_normalize_language((string)($transcript['language'] ?? ''));
    if ($sourceLanguage !== '' && $sourceLanguage === $targetLanguage) {
        return ['status' => 'skipped', 'reason' => 'same_language', 'message' => 'Транскрипт уже на выбранном языке.'];
    }
    $existing = tl_existing_translation($transcriptId, $targetLanguage);
    if ($existing) {
        return ['status' => 'ready', 'translation_id' => (int)$existing['id'], 'message' => 'Перевод на этот язык уже существует.'];
    }
    $active = db()->prepare("SELECT * FROM transcript_translation_jobs WHERE transcript_id=? AND target_language=? AND status IN ('pending','running') ORDER BY id DESC LIMIT 1");
    $active->execute([$transcriptId, $targetLanguage]);
    if ($row = $active->fetch()) return tl_job_payload($row);

    $settings = tl_assert_ready();
    $stmt = db()->prepare("INSERT INTO transcript_translation_jobs (transcript_id,target_language,provider,model,status,progress_percent,message) VALUES (?,?,?,?, 'pending',0,'Ожидание запуска…')");
    $stmt->execute([$transcriptId, $targetLanguage, $settings['provider'], $settings['model']]);
    $jobId = (int)db()->lastInsertId();
    tl_launch_job($jobId);
    return tl_get_job($jobId);
}

function tl_job_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'transcript_id' => (int)$row['transcript_id'],
        'target_language' => (string)$row['target_language'],
        'provider' => (string)$row['provider'],
        'model' => (string)($row['model'] ?? ''),
        'status' => (string)$row['status'],
        'progress_percent' => (int)($row['progress_percent'] ?? 0),
        'message' => (string)($row['message'] ?? ''),
        'translation_id' => $row['translation_id'] ? (int)$row['translation_id'] : null,
    ];
}

function tl_get_job(int $jobId): array
{
    tl_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM transcript_translation_jobs WHERE id=? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Задание перевода не найдено.');
    return tl_job_payload($row);
}

function tl_launch_job(int $jobId): void
{
    $php = sw_php_cli_path();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'translation_worker.php';
    if (!is_file($script)) throw new RuntimeException('Не найден translation_worker.php.');
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd.exe /D /S /C start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . $jobId . ' >NUL 2>&1';
        $h = @popen($command, 'r');
        if ($h === false) throw new RuntimeException('Не удалось запустить фоновый перевод.');
        pclose($h);
    } else {
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . $jobId . ' >/dev/null 2>&1 &';
        @exec($command, $out, $code);
        if ($code !== 0) throw new RuntimeException('Не удалось запустить фоновый перевод.');
    }
}

function tl_store_translation(int $transcriptId, string $provider, string $model, string $sourceLanguage, string $targetLanguage, array $translatedSegments): int
{
    tl_ensure_schema();
    $pdo = db();
    $source = tl_source_segments($transcriptId);
    $sourceByOrder = [];
    foreach ($source as $seg) $sourceByOrder[(int)$seg['id']] = $seg;
    $pdo->beginTransaction();
    try {
        $fullText = implode("\n", array_map(static fn(array $x): string => (string)$x['text'], $translatedSegments));
        $old = tl_existing_translation($transcriptId, $targetLanguage);
        $variantKey = 'lang:' . $targetLanguage;
        if ($old) {
            $translationId = (int)$old['id'];
            $pdo->prepare("UPDATE file_transcript_translations SET provider=?,model=?,source_language=?,translation_type='machine',custom_name=NULL,variant_key=?,full_text=?,updated_at=NOW() WHERE id=?")
                ->execute([$provider, $model, $sourceLanguage !== '' ? $sourceLanguage : null, $variantKey, $fullText, $translationId]);
            $pdo->prepare('DELETE FROM file_transcript_translation_segments WHERE translation_id=?')->execute([$translationId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO file_transcript_translations (transcript_id,provider,model,source_language,target_language,translation_type,custom_name,variant_key,full_text) VALUES (?,?,?,?,?,'machine',NULL,?,?)");
            $stmt->execute([$transcriptId, $provider, $model, $sourceLanguage !== '' ? $sourceLanguage : null, $targetLanguage, $variantKey, $fullText]);
            $translationId = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO file_transcript_translation_segments (translation_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
        foreach ($translatedSegments as $seg) {
            $order = (int)$seg['id'];
            $src = $sourceByOrder[$order] ?? null;
            $ins->execute([
                $translationId,
                $order,
                $src ? (float)$src['start'] : null,
                $src ? (float)$src['end'] : null,
                (string)$seg['text'],
            ]);
        }
        $pdo->commit();
        return $translationId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tl_list_for_transcript(int $transcriptId): array
{
    tl_ensure_schema();
    $stmt = db()->prepare('SELECT id,provider,model,source_language,target_language,translation_type,custom_name,created_at,updated_at FROM file_transcript_translations WHERE transcript_id=? ORDER BY created_at,id');
    $stmt->execute([$transcriptId]);
    $catalog = tl_language_catalog();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $lang = (string)$row['target_language'];
        $type = (string)($row['translation_type'] ?? 'machine');
        $customName = trim((string)($row['custom_name'] ?? ''));
        $label = $type === 'custom' ? 'Пользовательский - ' . ($customName !== '' ? $customName : 'без названия') : ($catalog[$lang]['label'] ?? strtoupper($lang));
        $out[] = [
            'id' => (int)$row['id'],
            'target_language' => $lang,
            'translation_type' => $type,
            'custom_name' => $customName,
            'language_label' => $label,
            'provider' => (string)$row['provider'],
            'model' => (string)($row['model'] ?? ''),
            'download_url' => 'translation_download.php?id=' . (int)$row['id'],
        ];
    }
    return $out;
}

function tl_active_job_for_transcript(int $transcriptId): ?array
{
    tl_ensure_schema();
    $stmt = db()->prepare("SELECT * FROM transcript_translation_jobs WHERE transcript_id=? AND status IN ('pending','running') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$transcriptId]);
    $row = $stmt->fetch();
    return $row ? tl_job_payload($row) : null;
}

function tl_translation_payloads(int $transcriptId): array
{
    tl_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM file_transcript_translations WHERE transcript_id=? ORDER BY created_at,id');
    $stmt->execute([$transcriptId]);
    $translations = [];
    $catalog = tl_language_catalog();
    foreach ($stmt->fetchAll() as $row) {
        $segStmt = db()->prepare('SELECT id,sort_order,start_seconds,end_seconds,segment_text FROM file_transcript_translation_segments WHERE translation_id=? ORDER BY COALESCE(start_seconds,999999999),id');
        $segStmt->execute([(int)$row['id']]);
        $segments = [];
        foreach ($segStmt->fetchAll() as $seg) {
            $segments[] = [
                'id' => (int)$seg['id'],
                'sort_order' => (int)$seg['sort_order'],
                'start' => $seg['start_seconds'] !== null ? (float)$seg['start_seconds'] : 0.0,
                'end' => $seg['end_seconds'] !== null ? (float)$seg['end_seconds'] : ($seg['start_seconds'] !== null ? (float)$seg['start_seconds'] : 0.0),
                'text' => (string)$seg['segment_text'],
            ];
        }
        $lang = (string)$row['target_language'];
        $type = (string)($row['translation_type'] ?? 'machine');
        $customName = trim((string)($row['custom_name'] ?? ''));
        $label = $type === 'custom' ? 'Пользовательский - ' . ($customName !== '' ? $customName : 'без названия') : ($catalog[$lang]['label'] ?? strtoupper($lang));
        $translations[] = [
            'id' => (int)$row['id'],
            'target_language' => $lang,
            'translation_type' => $type,
            'custom_name' => $customName,
            'language_label' => $label,
            'provider' => (string)$row['provider'],
            'model' => (string)($row['model'] ?? ''),
            'full_text' => (string)$row['full_text'],
            'segments' => $segments,
            'download_url' => 'translation_download.php?id=' . (int)$row['id'],
        ];
    }
    return $translations;
}

function tl_translation_for_download(int $translationId): array
{
    tl_ensure_schema();
    $stmt = db()->prepare(
        'SELECT t.*, ft.id AS transcript_id, lf.file_name, fc.custom_title
         FROM file_transcript_translations t
         INNER JOIN file_transcripts ft ON ft.id=t.transcript_id
         INNER JOIN library_files lf ON lf.id=ft.library_file_id
         LEFT JOIN file_cards fc ON fc.file_hash=lf.file_hash
         WHERE t.id=? LIMIT 1'
    );
    $stmt->execute([$translationId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Перевод не найден.');
    $segStmt = db()->prepare('SELECT start_seconds,segment_text FROM file_transcript_translation_segments WHERE translation_id=? ORDER BY COALESCE(start_seconds,999999999),id');
    $segStmt->execute([$translationId]);
    $lines = [];
    foreach ($segStmt->fetchAll() as $seg) {
        $start = $seg['start_seconds'] !== null ? (float)$seg['start_seconds'] : 0.0;
        $lines[] = '[' . tr_format_time($start) . '] ' . (string)$seg['segment_text'];
    }
    $title = trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : pathinfo((string)$row['file_name'], PATHINFO_FILENAME);
    $safe = preg_replace('~[\\/:*?"<>|]+~u', '_', $title) ?: 'transcript';
    $type = (string)($row['translation_type'] ?? 'machine');
    if ($type === 'custom') {
        $suffix = preg_replace('~[\\/:*?"<>|]+~u', '_', trim((string)($row['custom_name'] ?? ''))) ?: 'custom';
        $name = $safe . '_translation_custom_' . $suffix . '.txt';
    } else {
        $name = $safe . '_translation_' . (string)$row['target_language'] . '.txt';
    }
    return ['name' => $name, 'body' => implode("\r\n\r\n", $lines) . "\r\n"];
}

function tl_decode_uploaded_text(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $detected = function_exists('mb_detect_encoding') ? mb_detect_encoding($raw, ['Windows-1251','CP1251','ISO-8859-5'], true) : false;
        if ($detected) $raw = mb_convert_encoding($raw, 'UTF-8', $detected);
    }
    return str_replace(["\r\n", "\r"], "\n", $raw);
}

function tl_parse_timecode(string $value): float
{
    $value = trim(str_replace(',', '.', $value));
    $parts = explode(':', $value);
    if (count($parts) === 2) {
        [$m,$s] = $parts;
        if (!is_numeric($m) || !is_numeric($s)) throw new RuntimeException('Некорректный тайм-код: ' . $value);
        return max(0.0, (float)$m * 60.0 + (float)$s);
    }
    if (count($parts) === 3) {
        [$h,$m,$s] = $parts;
        if (!is_numeric($h) || !is_numeric($m) || !is_numeric($s)) throw new RuntimeException('Некорректный тайм-код: ' . $value);
        return max(0.0, (float)$h * 3600.0 + (float)$m * 60.0 + (float)$s);
    }
    throw new RuntimeException('Тайм-код должен иметь вид [hh:mm:ss] или [mm:ss].');
}

function tl_parse_timestamped_text(string $raw): array
{
    $raw = tl_decode_uploaded_text($raw);
    $segments = [];
    $current = null;
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^\s*\[\s*([0-9]{1,3}:[0-9]{1,2}(?::[0-9]{1,2}(?:[.,][0-9]{1,3})?)?|[0-9]{1,3}:[0-9]{1,2}(?:[.,][0-9]{1,3})?)\s*\]\s*(.*)$/u', $line, $m)) {
            if ($current !== null) $segments[] = $current;
            $current = ['start' => tl_parse_timecode($m[1]), 'text' => trim((string)$m[2])];
        } elseif ($current !== null && trim($line) !== '') {
            $current['text'] .= ($current['text'] !== '' ? "\n" : '') . trim($line);
        }
    }
    if ($current !== null) $segments[] = $current;
    $segments = array_values(array_filter($segments, static fn(array $x): bool => trim((string)$x['text']) !== ''));
    if (!$segments) throw new RuntimeException('В файле не найдено фрагментов вида [hh:mm:ss] текст.');
    usort($segments, static fn(array $a,array $b): int => $a['start'] <=> $b['start']);
    return $segments;
}

function tl_custom_translation_import(int $transcriptId, string $customName, string $rawText): int
{
    tl_ensure_schema();
    $customName = trim($customName);
    if ($customName === '') throw new RuntimeException('Введите название пользовательского перевода.');
    if ((function_exists('mb_strlen') ? mb_strlen($customName, 'UTF-8') : strlen($customName)) > 190) throw new RuntimeException('Название перевода слишком длинное.');
    $transcript = tl_transcript_row($transcriptId);
    $parsed = tl_parse_timestamped_text($rawText);
    $sourceMax = 0.0;
    $q = db()->prepare('SELECT MAX(end_seconds) FROM file_transcript_segments WHERE transcript_id=?');
    $q->execute([$transcriptId]);
    $sourceMax = (float)($q->fetchColumn() ?: 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $variant = 'custom:' . bin2hex(random_bytes(12));
        $full = implode("\n", array_column($parsed, 'text'));
        $stmt = $pdo->prepare("INSERT INTO file_transcript_translations (transcript_id,provider,model,source_language,target_language,translation_type,custom_name,variant_key,full_text) VALUES (?,'manual',NULL,?,'custom','custom',?,?,?)");
        $stmt->execute([$transcriptId, trim((string)($transcript['language'] ?? '')) ?: null, $customName, $variant, $full]);
        $translationId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO file_transcript_translation_segments (translation_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
        foreach ($parsed as $i => $seg) {
            $start = (float)$seg['start'];
            $next = isset($parsed[$i+1]) ? (float)$parsed[$i+1]['start'] : max($start, $sourceMax);
            if ($next < $start) $next = $start;
            $ins->execute([$translationId, $i, $start, $next, (string)$seg['text']]);
        }
        $pdo->commit();
        return $translationId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tl_delete_translation(int $translationId): void
{
    tl_ensure_schema();
    $stmt = db()->prepare('DELETE FROM file_transcript_translations WHERE id=?');
    $stmt->execute([$translationId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('Перевод не найден.');
}

function tl_rebuild_translation_text(int $translationId): void
{
    $stmt = db()->prepare('SELECT segment_text FROM file_transcript_translation_segments WHERE translation_id=? ORDER BY COALESCE(start_seconds,999999999),id');
    $stmt->execute([$translationId]);
    $full = implode("\n", array_map(static fn(array $x): string => trim((string)$x['segment_text']), $stmt->fetchAll()));
    db()->prepare('UPDATE file_transcript_translations SET full_text=?,updated_at=NOW() WHERE id=?')->execute([$full,$translationId]);
}

function tl_update_translation_segment(int $translationId, int $segmentId, string $text): void
{
    tl_ensure_schema();
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Текст фрагмента не может быть пустым. Используйте удаление фрагмента.');
    $stmt = db()->prepare('UPDATE file_transcript_translation_segments SET segment_text=? WHERE id=? AND translation_id=?');
    $stmt->execute([$text,$segmentId,$translationId]);
    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM file_transcript_translation_segments WHERE id=? AND translation_id=?');
        $check->execute([$segmentId,$translationId]);
        if (!$check->fetchColumn()) throw new RuntimeException('Фрагмент перевода не найден.');
    }
    tl_rebuild_translation_text($translationId);
}

function tl_delete_translation_segment(int $translationId, int $segmentId): void
{
    tl_ensure_schema();
    $stmt = db()->prepare('DELETE FROM file_transcript_translation_segments WHERE id=? AND translation_id=?');
    $stmt->execute([$segmentId,$translationId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('Фрагмент перевода не найден.');
    tl_rebuild_translation_text($translationId);
}

function tl_add_translation_segment(int $translationId, string $input): int
{
    tl_ensure_schema();
    $parsed = tl_parse_timestamped_text($input);
    if (count($parsed) !== 1) throw new RuntimeException('Для добавления укажите ровно один фрагмент: [hh:mm:ss] текст.');
    $seg = $parsed[0];
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM file_transcript_translation_segments WHERE translation_id=?');
    $stmt->execute([$translationId]);
    $order = (int)$stmt->fetchColumn();
    $next = db()->prepare('SELECT MIN(start_seconds) FROM file_transcript_translation_segments WHERE translation_id=? AND start_seconds>?');
    $next->execute([$translationId,(float)$seg['start']]);
    $end = $next->fetchColumn();
    $end = $end !== null ? (float)$end : (float)$seg['start'];
    $ins = db()->prepare('INSERT INTO file_transcript_translation_segments (translation_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
    $ins->execute([$translationId,$order,(float)$seg['start'],$end,(string)$seg['text']]);
    $id = (int)db()->lastInsertId();
    tl_rebuild_translation_text($translationId);
    return $id;
}

