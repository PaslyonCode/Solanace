<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/library_identity.php';
require_once __DIR__ . '/library_categories.php';
require_once __DIR__ . '/file_tools_lib.php';
require_once __DIR__ . '/video_merge_lib.php';

const LT_EXPORT_VERSION = 1;

function lt_ensure_schema(): void
{
    li_ensure_schema();
    lc_ensure_schema();
    ft_ensure_schema();
    tr_ensure_schema();
    tl_ensure_schema();
    vm_ensure_schema();
    sw_ensure_schema();
}

function lt_require_zip(): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Для экспорта/импорта требуется PHP-расширение ZipArchive (ext-zip).');
    }
}

function lt_norm_rel(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('~/+~', '/', $path) ?? $path;
    return ltrim($path, '/');
}

function lt_safe_rel(string $path): string
{
    $path = lt_norm_rel($path);
    if ($path === '' || str_contains($path, "\0") || preg_match('~(^|/)\.\.(/|$)~', $path) || preg_match('~^[A-Za-z]:/~', $path)) {
        throw new RuntimeException('Некорректный относительный путь в архиве: ' . $path);
    }
    return $path;
}

function lt_import_path_prefix(string $path): string
{
    $path = rtrim(lt_norm_rel($path), '/');
    if ($path === '' || $path === '.') return '';
    return lt_safe_rel($path);
}

function lt_apply_import_prefix(string $prefix, string $relativePath): string
{
    $relativePath = lt_safe_rel($relativePath);
    if ($prefix === '') return $relativePath;
    return lt_safe_rel($prefix . '/' . $relativePath);
}

function lt_rel_is_inside_prefix(string $relativePath, string $prefix): bool
{
    if ($prefix === '') return true;
    $rel = mb_strtolower(lt_norm_rel($relativePath), 'UTF-8');
    $prefixKey = mb_strtolower(rtrim(lt_norm_rel($prefix), '/'), 'UTF-8');
    return $rel === $prefixKey || str_starts_with($rel, $prefixKey . '/');
}

function lt_export_file_name(): string
{
    return 'solanace_export_' . date('Y-m-d_H-i-s') . '.zip';
}

function lt_json_encode(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Не удалось сформировать JSON экспорта.');
    return $json;
}

function lt_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lt_collect_export_data(array $root): array
{
    $rootId = (int)$root['id'];
    $pdo = db();

    $files = lt_rows('SELECT id, relative_path, file_name, file_hash, file_size, file_mtime, is_pinned, first_seen_at, last_seen_at FROM library_files WHERE root_id = ? ORDER BY relative_path', [$rootId]);
    $fileIds = array_map(static fn($r) => (int)$r['id'], $files);
    $fileHashes = array_values(array_unique(array_map(static fn($r) => (string)$r['file_hash'], $files)));

    $dirs = lt_rows('SELECT id, relative_path, dir_name, first_seen_at, last_seen_at FROM library_dirs WHERE root_id = ? ORDER BY relative_path', [$rootId]);
    $categories = lt_rows('SELECT id, name, created_at FROM categories WHERE root_id = ? ORDER BY name', [$rootId]);
    $assignments = lt_rows(
        'SELECT lfc.library_file_id, c.name AS category_name FROM library_file_categories lfc INNER JOIN library_files lf ON lf.id=lfc.library_file_id INNER JOIN categories c ON c.id=lfc.category_id WHERE lf.root_id=?',
        [$rootId]
    );

    $cards = [];
    if ($fileHashes) {
        $placeholders = implode(',', array_fill(0, count($fileHashes), '?'));
        $cardRows = lt_rows("SELECT id, file_hash, custom_title, note, created_at, updated_at FROM file_cards WHERE file_hash IN ($placeholders)", $fileHashes);
        $imgStmt = $pdo->prepare('SELECT id, filename, original_name, created_at FROM file_images WHERE card_id=? ORDER BY id');
        foreach ($cardRows as $card) {
            $imgStmt->execute([(int)$card['id']]);
            $images = [];
            foreach ($imgStmt->fetchAll() as $img) {
                $file = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string)$img['filename']);
                $images[] = [
                    'legacy_id' => (int)$img['id'],
                    'filename' => basename((string)$img['filename']),
                    'original_name' => (string)($img['original_name'] ?? ''),
                    'created_at' => $img['created_at'],
                    'sha1' => is_file($file) ? sha1_file($file) : null,
                    'archive_path' => 'attachments/' . strtolower((string)$card['file_hash']) . '/' . (int)$img['id'] . '_' . basename((string)$img['filename']),
                ];
            }
            $cards[] = [
                'legacy_id' => (int)$card['id'],
                'file_hash' => (string)$card['file_hash'],
                'custom_title' => $card['custom_title'],
                'note' => $card['note'],
                'created_at' => $card['created_at'],
                'updated_at' => $card['updated_at'],
                'images' => $images,
            ];
        }
    }

    $screenshotSets = lt_rows('SELECT file_hash,status,expected_count,source_file_size,source_file_mtime,last_error,thumbnail_sort_order,duration_seconds,updated_at FROM root_video_screenshot_sets WHERE root_id=?', [$rootId]);
    $screenshots = lt_rows('SELECT id,file_hash,relative_path,position_seconds,sort_order,created_at FROM root_video_screenshots WHERE root_id=? ORDER BY file_hash,sort_order', [$rootId]);

    $derivatives = lt_rows('SELECT id,library_file_id,source_hash,kind,relative_path,download_name,start_seconds,end_seconds,original_extension,created_at FROM file_derivatives WHERE root_id=? ORDER BY id', [$rootId]);

    $transcripts = [];
    $trRows = lt_rows('SELECT * FROM file_transcripts WHERE root_id=? ORDER BY id', [$rootId]);
    $segStmt = $pdo->prepare('SELECT sort_order,start_seconds,end_seconds,segment_text FROM file_transcript_segments WHERE transcript_id=? ORDER BY sort_order');
    $translationStmt = $pdo->prepare('SELECT * FROM file_transcript_translations WHERE transcript_id=? ORDER BY id');
    $translationSegStmt = $pdo->prepare('SELECT sort_order,start_seconds,end_seconds,segment_text FROM file_transcript_translation_segments WHERE translation_id=? ORDER BY sort_order');
    foreach ($trRows as $tr) {
        $segStmt->execute([(int)$tr['id']]);
        $translations = [];
        $translationStmt->execute([(int)$tr['id']]);
        foreach ($translationStmt->fetchAll() as $translation) {
            $translationSegStmt->execute([(int)$translation['id']]);
            $translations[] = [
                'legacy_id' => (int)$translation['id'],
                'provider' => $translation['provider'],
                'model' => $translation['model'],
                'source_language' => $translation['source_language'],
                'target_language' => $translation['target_language'],
                'translation_type' => $translation['translation_type'],
                'custom_name' => $translation['custom_name'],
                'variant_key' => $translation['variant_key'],
                'full_text' => $translation['full_text'],
                'created_at' => $translation['created_at'],
                'updated_at' => $translation['updated_at'],
                'segments' => $translationSegStmt->fetchAll(),
            ];
        }
        $transcripts[] = [
            'legacy_id' => (int)$tr['id'],
            'library_file_id' => (int)$tr['library_file_id'],
            'source_hash' => $tr['source_hash'],
            'audio_derivative_id' => $tr['audio_derivative_id'] !== null ? (int)$tr['audio_derivative_id'] : null,
            'text_derivative_id' => (int)$tr['text_derivative_id'],
            'provider' => $tr['provider'],
            'model' => $tr['model'],
            'language' => $tr['language'],
            'start_seconds' => $tr['start_seconds'],
            'end_seconds' => $tr['end_seconds'],
            'full_text' => $tr['full_text'],
            'created_at' => $tr['created_at'],
            'segments' => $segStmt->fetchAll(),
            'translations' => $translations,
        ];
    }

    $merges = [];
    $mergeRows = lt_rows('SELECT id,output_library_file_id,output_file_hash,output_name,created_at FROM video_merges WHERE root_id=? ORDER BY id', [$rootId]);
    $mergeSourcesStmt = $pdo->prepare('SELECT source_order,source_library_file_id,source_file_hash,source_file_name,source_relative_path FROM video_merge_sources WHERE merge_id=? ORDER BY source_order');
    foreach ($mergeRows as $merge) {
        $mergeSourcesStmt->execute([(int)$merge['id']]);
        $merges[] = [
            'legacy_id' => (int)$merge['id'],
            'output_library_file_id' => (int)$merge['output_library_file_id'],
            'output_file_hash' => $merge['output_file_hash'],
            'output_name' => $merge['output_name'],
            'created_at' => $merge['created_at'],
            'sources' => $mergeSourcesStmt->fetchAll(),
        ];
    }

    $promoted = lt_rows('SELECT source_library_file_id,promoted_library_file_id,source_hash,promoted_hash,original_clip_name,created_at FROM promoted_clips WHERE root_id=? ORDER BY id', [$rootId]);

    return [
        'format' => 'solanace-library-export',
        'version' => LT_EXPORT_VERSION,
        'created_at' => date(DATE_ATOM),
        'source_library' => [
            'library_uid' => (string)$root['library_uid'],
            'root_name' => basename(str_replace('\\', '/', (string)$root['root_path'])),
        ],
        'files' => array_map(static fn($r) => [
            'legacy_id' => (int)$r['id'],
            'relative_path' => lt_norm_rel((string)$r['relative_path']),
            'file_name' => (string)$r['file_name'],
            'file_hash' => (string)$r['file_hash'],
            'file_size' => (int)$r['file_size'],
            'file_mtime' => (int)$r['file_mtime'],
            'is_pinned' => (int)$r['is_pinned'],
            'first_seen_at' => $r['first_seen_at'],
            'last_seen_at' => $r['last_seen_at'],
        ], $files),
        'directories' => array_map(static fn($r) => [
            'legacy_id' => (int)$r['id'],
            'relative_path' => lt_norm_rel((string)$r['relative_path']),
            'dir_name' => (string)$r['dir_name'],
            'first_seen_at' => $r['first_seen_at'],
            'last_seen_at' => $r['last_seen_at'],
        ], $dirs),
        'categories' => $categories,
        'category_assignments' => $assignments,
        'cards' => $cards,
        'screenshot_sets' => $screenshotSets,
        'screenshots' => $screenshots,
        'derivatives' => $derivatives,
        'transcripts' => $transcripts,
        'merges' => $merges,
        'promoted_clips' => $promoted,
    ];
}

function lt_zip_add_service_cache(ZipArchive $zip, array $root): int
{
    $service = li_screenshot_dir((string)$root['root_path']);
    if (!is_dir($service)) return 0;
    $baseLen = strlen(rtrim($service, "\\/")) + 1;
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($service, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $info) {
        if (!$info->isFile() || $info->isLink()) continue;
        $abs = $info->getPathname();
        $rel = str_replace('\\', '/', substr($abs, $baseLen));
        if ($rel === '' || $rel === 'library.id' || $rel === '.htaccess') continue;
        if (preg_match('~(^|/)_merge_job_~', $rel) || preg_match('~\.(lock|progress|stderr|stdout)$~i', $rel)) continue;
        $zip->addFile($abs, 'cache/' . $rel);
        $count++;
    }
    return $count;
}

function lt_zip_add_attachments(ZipArchive $zip, array $data): int
{
    $count = 0;
    foreach ($data['cards'] ?? [] as $card) {
        foreach ($card['images'] ?? [] as $img) {
            $filename = basename((string)($img['filename'] ?? ''));
            $archivePath = (string)($img['archive_path'] ?? '');
            if ($filename === '' || $archivePath === '') continue;
            $abs = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($abs)) continue;
            $zip->addFile($abs, $archivePath);
            $count++;
        }
    }
    return $count;
}

function lt_export_library(string $rootPath): array
{
    lt_require_zip();
    lt_ensure_schema();
    $root = li_resolve_root($rootPath, false);
    $rootPath = li_normalize_root_path((string)$root['root_path']);
    if (!is_dir($rootPath) || !is_writable($rootPath)) throw new RuntimeException('Корневая папка недоступна для записи экспорта.');

    @set_time_limit(0);
    $data = lt_collect_export_data($root);
    $fileName = lt_export_file_name();
    $target = $rootPath . DIRECTORY_SEPARATOR . $fileName;
    $tmp = $target . '.part-' . bin2hex(random_bytes(4));

    $zip = new ZipArchive();
    $opened = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) throw new RuntimeException('Не удалось создать ZIP-архив экспорта.');
    try {
        $manifest = [
            'format' => 'solanace-library-export',
            'version' => LT_EXPORT_VERSION,
            'created_at' => $data['created_at'],
            'source_library' => $data['source_library'],
            'database_file' => 'database.json',
            'cache_prefix' => 'cache/',
            'attachments_prefix' => 'attachments/',
            'contains_source_videos' => false,
            'secrets_exported' => false,
        ];
        $zip->addFromString('manifest.json', lt_json_encode($manifest));
        $zip->addFromString('database.json', lt_json_encode($data));
        $cacheCount = lt_zip_add_service_cache($zip, $root);
        $attachmentCount = lt_zip_add_attachments($zip, $data);
        if (!$zip->close()) throw new RuntimeException('Не удалось завершить ZIP-архив экспорта.');
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось переместить готовый экспорт в корень библиотеки.');
        }
        return [
            'file_name' => $fileName,
            'path' => $target,
            'size' => (int)filesize($target),
            'files' => count($data['files']),
            'cache_files' => $cacheCount,
            'attachments' => $attachmentCount,
        ];
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $ignored) {}
        @unlink($tmp);
        throw $e;
    }
}

function lt_list_exports(string $rootPath): array
{
    $root = li_resolve_root($rootPath, false);
    $path = li_normalize_root_path((string)$root['root_path']);
    if (!is_dir($path)) return [];
    $items = [];
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isFile()) continue;
        $name = $entry->getFilename();
        if (!preg_match('/^solanace_export_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.zip$/', $name)) continue;
        $items[] = ['name' => $name, 'size' => $entry->getSize(), 'mtime' => $entry->getMTime()];
    }
    usort($items, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $items;
}

function lt_validate_zip_name(string $name): string
{
    $name = basename(trim($name));
    if (!preg_match('/^solanace_export_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.zip$/', $name)) {
        throw new RuntimeException('Выбранный ZIP не является экспортом Solanace.');
    }
    return $name;
}

function lt_zip_json(ZipArchive $zip, string $name): array
{
    $raw = $zip->getFromName($name);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('В архиве отсутствует ' . $name . '.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Некорректный JSON в ' . $name . '.');
    return $data;
}

function lt_validate_archive(ZipArchive $zip): array
{
    $manifest = lt_zip_json($zip, 'manifest.json');
    if (($manifest['format'] ?? '') !== 'solanace-library-export') throw new RuntimeException('Это не архив экспорта Solanace.');
    $version = (int)($manifest['version'] ?? 0);
    if ($version <= 0 || $version > LT_EXPORT_VERSION) throw new RuntimeException('Версия архива не поддерживается этой версией Solanace.');
    if ($zip->numFiles > 200000) throw new RuntimeException('В архиве слишком много файлов.');
    $total = 0;
    for ($i=0; $i<$zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name) || preg_match('~^[A-Za-z]:/~', $name)) {
            throw new RuntimeException('Архив содержит небезопасный путь.');
        }
        $total += (int)($stat['size'] ?? 0);
        if ($total > 150 * 1024 * 1024 * 1024) throw new RuntimeException('Распакованный архив превышает допустимый размер 150 ГБ.');
    }
    return $manifest;
}

function lt_copy_zip_entry(ZipArchive $zip, string $entryName, string $destination): void
{
    $stream = $zip->getStream($entryName);
    if (!is_resource($stream)) throw new RuntimeException('Не удалось прочитать файл из ZIP: ' . $entryName);
    $dir = dirname($destination);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fclose($stream);
        throw new RuntimeException('Не удалось создать папку при импорте: ' . $dir);
    }
    $out = @fopen($destination, 'wb');
    if (!$out) {
        fclose($stream);
        throw new RuntimeException('Не удалось записать файл при импорте: ' . $destination);
    }
    stream_copy_to_stream($stream, $out);
    fclose($stream);
    fclose($out);
}

function lt_restore_cache(ZipArchive $zip, array $root): int
{
    $service = li_screenshot_dir((string)$root['root_path']);
    if (!is_dir($service) && !@mkdir($service, 0775, true) && !is_dir($service)) {
        throw new RuntimeException('Не удалось создать служебную папку библиотеки.');
    }
    li_write_service_dir_protection($service);
    $count = 0;
    for ($i=0; $i<$zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
        if (!str_starts_with($name, 'cache/') || str_ends_with($name, '/')) continue;
        $rel = substr($name, 6);
        if ($rel === '' || $rel === 'library.id' || $rel === '.htaccess') continue;
        lt_safe_rel($rel);
        $target = $service . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        lt_copy_zip_entry($zip, $name, $target);
        $count++;
    }
    li_write_marker((string)$root['root_path'], (string)$root['library_uid']);
    return $count;
}

function lt_target_file_maps(array $root): array
{
    $rows = lt_rows('SELECT * FROM library_files WHERE root_id=?', [(int)$root['id']]);
    $byRel = [];
    $byHash = [];
    foreach ($rows as $row) {
        $byRel[mb_strtolower(lt_norm_rel((string)$row['relative_path']), 'UTF-8')] = $row;
        $byHash[strtolower((string)$row['file_hash'])][] = $row;
    }
    return [$byRel, $byHash];
}

function lt_map_or_create_files(array $root, array $exportedFiles, string $pathPrefix = ''): array
{
    $pathPrefix = lt_import_path_prefix($pathPrefix);
    [$byRel, $byHash] = lt_target_file_maps($root);
    $pdo = db();
    $insert = $pdo->prepare('INSERT INTO library_files (root_id,relative_path,file_path,path_key,file_name,file_hash,file_size,file_mtime,is_pinned,last_scan_token,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $updatePin = $pdo->prepare('UPDATE library_files SET is_pinned=? WHERE id=?');
    $map = [];
    $missing = [];
    $mappedRows = [];

    foreach ($exportedFiles as $item) {
        $legacyId = (int)($item['legacy_id'] ?? 0);
        $sourceRel = lt_safe_rel((string)($item['relative_path'] ?? ''));
        $rel = lt_apply_import_prefix($pathPrefix, $sourceRel);
        $hash = strtolower(trim((string)($item['file_hash'] ?? '')));
        if ($legacyId <= 0 || !preg_match('/^[a-f0-9]{40}$/', $hash)) continue;

        $relKey = mb_strtolower($rel, 'UTF-8');
        $row = $byRel[$relKey] ?? null;
        if ($row && strtolower((string)$row['file_hash']) !== $hash) $row = null;

        // Hash fallback remains useful after a scan, but when a prefix is explicitly
        // supplied it may only match files inside that subtree.
        if (!$row && isset($byHash[$hash])) {
            $candidates = $byHash[$hash];
            if ($pathPrefix !== '') {
                $candidates = array_values(array_filter($candidates, static fn($candidate) =>
                    lt_rel_is_inside_prefix((string)($candidate['relative_path'] ?? ''), $pathPrefix)
                ));
            }
            if (count($candidates) === 1) $row = $candidates[0];
        }

        if (!$row) {
            $abs = li_join_root_relative((string)$root['root_path'], $rel);
            if (!is_file($abs)) {
                $missing[] = $sourceRel . ($pathPrefix !== '' ? ' → ' . $rel : '');
                continue;
            }
            $actualSize = (int)@filesize($abs);
            if ((int)($item['file_size'] ?? 0) > 0 && $actualSize !== (int)$item['file_size']) {
                $missing[] = $sourceRel . ' (размер отличается' . ($pathPrefix !== '' ? '; путь: ' . $rel : '') . ')';
                continue;
            }
            $actualHash = strtolower(ft_file_hash($abs));
            if ($actualHash !== $hash) {
                $missing[] = $sourceRel . ' (хэш отличается' . ($pathPrefix !== '' ? '; путь: ' . $rel : '') . ')';
                continue;
            }
            $first = (string)($item['first_seen_at'] ?? date('Y-m-d H:i:s'));
            $last = (string)($item['last_seen_at'] ?? date('Y-m-d H:i:s'));
            $insert->execute([
                (int)$root['id'], $rel, $abs, li_path_key($abs), basename(str_replace('\\','/',$abs)), $hash,
                $actualSize, (int)@filemtime($abs), (int)($item['is_pinned'] ?? 0), '', $first, $last,
            ]);
            $row = [
                'id' => (int)$pdo->lastInsertId(), 'root_id' => (int)$root['id'], 'relative_path' => $rel,
                'file_path' => $abs, 'file_name' => basename(str_replace('\\','/',$abs)), 'file_hash' => $hash,
            ];
            $byRel[$relKey] = $row;
            $byHash[$hash][] = $row;
        } else {
            $updatePin->execute([(int)($item['is_pinned'] ?? 0), (int)$row['id']]);
        }

        $map[$legacyId] = (int)$row['id'];
        $mappedRows[$legacyId] = $row;
    }
    return [$map, $mappedRows, $missing];
}

function lt_import_directories(array $root, array $dirs, string $pathPrefix = ''): int
{
    $pathPrefix = lt_import_path_prefix($pathPrefix);
    $pdo = db();
    $select = $pdo->prepare('SELECT id FROM library_dirs WHERE root_id=? AND path_key=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO library_dirs (root_id,relative_path,dir_path,path_key,dir_name,last_scan_token,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?)');
    $count = 0;

    // Make sure the prefix itself appears in the directory cache if it exists.
    if ($pathPrefix !== '') {
        $parts = explode('/', $pathPrefix);
        $acc = '';
        foreach ($parts as $part) {
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $abs = li_join_root_relative((string)$root['root_path'], $acc);
            if (!is_dir($abs)) continue;
            $key = li_path_key($abs);
            $select->execute([(int)$root['id'], $key]);
            if (!$select->fetchColumn()) {
                $now = date('Y-m-d H:i:s');
                $insert->execute([(int)$root['id'],$acc,$abs,$key,basename(str_replace('\\','/',$abs)),'',$now,$now]);
            }
        }
    }

    foreach ($dirs as $item) {
        $relRaw = (string)($item['relative_path'] ?? '');
        if ($relRaw === '') continue;
        $rel = lt_apply_import_prefix($pathPrefix, $relRaw);
        $abs = li_join_root_relative((string)$root['root_path'], $rel);
        if (!is_dir($abs)) continue;
        $key = li_path_key($abs);
        $select->execute([(int)$root['id'], $key]);
        if (!$select->fetchColumn()) {
            $insert->execute([(int)$root['id'],$rel,$abs,$key,basename(str_replace('\\','/',$abs)),'',(string)($item['first_seen_at'] ?? date('Y-m-d H:i:s')),(string)($item['last_seen_at'] ?? date('Y-m-d H:i:s'))]);
        }
        $count++;
    }
    return $count;
}

function lt_import_categories(array $root, array $categories): array
{
    $pdo = db();
    $select = $pdo->prepare('SELECT id FROM categories WHERE root_id=? AND name=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO categories (root_id,name) VALUES (?,?)');
    $map = [];
    foreach ($categories as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') continue;
        $select->execute([(int)$root['id'],$name]);
        $id = (int)($select->fetchColumn() ?: 0);
        if (!$id) { $insert->execute([(int)$root['id'],$name]); $id = (int)$pdo->lastInsertId(); }
        $map[$name] = $id;
    }
    return $map;
}

function lt_import_category_assignments(array $assignments, array $fileMap, array $categoryMap): int
{
    $pdo = db();
    $upsert = $pdo->prepare('INSERT INTO library_file_categories (library_file_id,category_id) VALUES (?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), updated_at=CURRENT_TIMESTAMP');
    $count=0;
    foreach ($assignments as $item) {
        $fid = $fileMap[(int)($item['library_file_id'] ?? 0)] ?? 0;
        $cid = $categoryMap[(string)($item['category_name'] ?? '')] ?? 0;
        if (!$fid || !$cid) continue;
        $upsert->execute([$fid,$cid]);
        $count++;
    }
    return $count;
}

function lt_card_existing_image_hashes(int $cardId): array
{
    $stmt = db()->prepare('SELECT filename FROM file_images WHERE card_id=?');
    $stmt->execute([$cardId]);
    $hashes=[];
    foreach ($stmt->fetchAll() as $row) {
        $file = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
        if (is_file($file)) $hashes[strtolower((string)sha1_file($file))]=true;
    }
    return $hashes;
}

function lt_import_cards(ZipArchive $zip, array $cards, array $mappedRows): array
{
    $pdo=db();
    $rowsByHash=[];
    foreach ($mappedRows as $row) $rowsByHash[strtolower((string)$row['file_hash'])][]=$row;
    ensure_upload_dir();
    $selectCard=$pdo->prepare('SELECT id FROM file_cards WHERE file_hash=? LIMIT 1');
    $insertCard=$pdo->prepare('INSERT INTO file_cards (file_path,file_hash,custom_title,note,category_id,created_at,updated_at) VALUES (?,?,?,?,NULL,?,?)');
    $updateCard=$pdo->prepare('UPDATE file_cards SET file_path=?,custom_title=?,note=?,category_id=NULL,updated_at=? WHERE id=?');
    $insertImg=$pdo->prepare('INSERT INTO file_images (card_id,filename,original_name,created_at) VALUES (?,?,?,?)');
    $cardsCount=0;$imagesCount=0;
    foreach ($cards as $card) {
        $hash=strtolower((string)($card['file_hash']??''));
        if (!isset($rowsByHash[$hash])) continue;
        $target=$rowsByHash[$hash][0];
        $selectCard->execute([$hash]);
        $cardId=(int)($selectCard->fetchColumn()?:0);
        $created=(string)($card['created_at']??date('Y-m-d H:i:s'));
        $updated=(string)($card['updated_at']??date('Y-m-d H:i:s'));
        if ($cardId) $updateCard->execute([(string)$target['file_path'],$card['custom_title']??null,$card['note']??null,$updated,$cardId]);
        else { $insertCard->execute([(string)$target['file_path'],$hash,$card['custom_title']??null,$card['note']??null,$created,$updated]); $cardId=(int)$pdo->lastInsertId(); }
        $cardsCount++;
        $existingHashes=lt_card_existing_image_hashes($cardId);
        foreach ($card['images']??[] as $img) {
            $archivePath=(string)($img['archive_path']??'');
            $sha=strtolower((string)($img['sha1']??''));
            if ($archivePath==='' || ($sha!=='' && isset($existingHashes[$sha]))) continue;
            if ($zip->locateName($archivePath)===false) continue;
            $ext=strtolower(pathinfo((string)($img['filename']??''),PATHINFO_EXTENSION));
            if (!in_array($ext,['jpg','jpeg','png','gif','webp'],true)) $ext='jpg';
            $newName='imp_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $dest=UPLOAD_DIR . DIRECTORY_SEPARATOR . $newName;
            lt_copy_zip_entry($zip,$archivePath,$dest);
            $actualSha=strtolower((string)sha1_file($dest));
            if ($sha!=='' && $actualSha!==$sha) { @unlink($dest); throw new RuntimeException('Контрольная сумма вложения не совпала при импорте.'); }
            $insertImg->execute([$cardId,$newName,(string)($img['original_name']??''),(string)($img['created_at']??date('Y-m-d H:i:s'))]);
            $existingHashes[$actualSha]=true;$imagesCount++;
        }
    }
    return [$cardsCount,$imagesCount];
}

function lt_import_screenshots(array $root, array $sets, array $screenshots, array $mappedRows): int
{
    $validHashes=[];foreach($mappedRows as $r)$validHashes[strtolower((string)$r['file_hash'])]=true;
    $pdo=db();
    $upSet=$pdo->prepare("INSERT INTO root_video_screenshot_sets (root_id,file_hash,status,expected_count,source_file_size,source_file_mtime,last_error,thumbnail_sort_order,duration_seconds) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),expected_count=VALUES(expected_count),source_file_size=VALUES(source_file_size),source_file_mtime=VALUES(source_file_mtime),last_error=VALUES(last_error),thumbnail_sort_order=VALUES(thumbnail_sort_order),duration_seconds=VALUES(duration_seconds)");
    foreach($sets as $s){$h=strtolower((string)($s['file_hash']??''));if(!isset($validHashes[$h]))continue;$upSet->execute([(int)$root['id'],$h,(string)($s['status']??'ready'),(int)($s['expected_count']??10),(int)($s['source_file_size']??0),(int)($s['source_file_mtime']??0),$s['last_error']??null,$s['thumbnail_sort_order']??null,$s['duration_seconds']??null]);}
    $group=[];foreach($screenshots as $s){$h=strtolower((string)($s['file_hash']??''));if(isset($validHashes[$h]))$group[$h][]=$s;}
    $del=$pdo->prepare('DELETE FROM root_video_screenshots WHERE root_id=? AND file_hash=?');
    $ins=$pdo->prepare('INSERT INTO root_video_screenshots (root_id,file_hash,relative_path,position_seconds,sort_order,created_at) VALUES (?,?,?,?,?,?)');
    $count=0;foreach($group as $h=>$items){$del->execute([(int)$root['id'],$h]);foreach($items as $s){$rel=lt_safe_rel((string)$s['relative_path']);$ins->execute([(int)$root['id'],$h,$rel,$s['position_seconds']??0,(int)($s['sort_order']??0),(string)($s['created_at']??date('Y-m-d H:i:s'))]);$count++;}}
    return $count;
}

function lt_import_derivatives(array $root,array $items,array $fileMap):array
{
    $pdo=db();$map=[];$count=0;
    $sel=$pdo->prepare('SELECT id FROM file_derivatives WHERE library_file_id=? AND kind=? AND relative_path=? LIMIT 1');
    $ins=$pdo->prepare('INSERT INTO file_derivatives (library_file_id,root_id,source_hash,kind,relative_path,download_name,start_seconds,end_seconds,original_extension,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $upd=$pdo->prepare('UPDATE file_derivatives SET root_id=?,source_hash=?,download_name=?,start_seconds=?,end_seconds=?,original_extension=? WHERE id=?');
    foreach($items as $d){$old=(int)($d['id']??0);$fid=$fileMap[(int)($d['library_file_id']??0)]??0;if(!$old||!$fid)continue;$rel=lt_safe_rel((string)$d['relative_path']);$kind=(string)$d['kind'];$sel->execute([$fid,$kind,$rel]);$id=(int)($sel->fetchColumn()?:0);if($id)$upd->execute([(int)$root['id'],(string)$d['source_hash'],(string)$d['download_name'],$d['start_seconds']??null,$d['end_seconds']??null,$d['original_extension']??null,$id]);else{$ins->execute([$fid,(int)$root['id'],(string)$d['source_hash'],$kind,$rel,(string)$d['download_name'],$d['start_seconds']??null,$d['end_seconds']??null,$d['original_extension']??null,(string)($d['created_at']??date('Y-m-d H:i:s'))]);$id=(int)$pdo->lastInsertId();}$map[$old]=$id;$count++;}
    return [$map,$count];
}

function lt_import_transcripts(array $root,array $items,array $fileMap,array $derivativeMap):int
{
    $pdo=db();$count=0;
    $sel=$pdo->prepare('SELECT id FROM file_transcripts WHERE library_file_id=? AND text_derivative_id=? LIMIT 1');
    $ins=$pdo->prepare('INSERT INTO file_transcripts (library_file_id,root_id,source_hash,audio_derivative_id,text_derivative_id,provider,model,language,start_seconds,end_seconds,full_text,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $upd=$pdo->prepare('UPDATE file_transcripts SET root_id=?,source_hash=?,audio_derivative_id=?,provider=?,model=?,language=?,start_seconds=?,end_seconds=?,full_text=? WHERE id=?');
    $delSeg=$pdo->prepare('DELETE FROM file_transcript_segments WHERE transcript_id=?');
    $insSeg=$pdo->prepare('INSERT INTO file_transcript_segments (transcript_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
    $selTrans=$pdo->prepare('SELECT id FROM file_transcript_translations WHERE transcript_id=? AND variant_key=? LIMIT 1');
    $insTrans=$pdo->prepare('INSERT INTO file_transcript_translations (transcript_id,provider,model,source_language,target_language,translation_type,custom_name,variant_key,full_text,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $updTrans=$pdo->prepare('UPDATE file_transcript_translations SET provider=?,model=?,source_language=?,target_language=?,translation_type=?,custom_name=?,full_text=?,updated_at=? WHERE id=?');
    $delTransSeg=$pdo->prepare('DELETE FROM file_transcript_translation_segments WHERE translation_id=?');
    $insTransSeg=$pdo->prepare('INSERT INTO file_transcript_translation_segments (translation_id,sort_order,start_seconds,end_seconds,segment_text) VALUES (?,?,?,?,?)');
    foreach($items as $tr){$fid=$fileMap[(int)($tr['library_file_id']??0)]??0;$textDid=$derivativeMap[(int)($tr['text_derivative_id']??0)]??0;if(!$fid||!$textDid)continue;$audioDid=isset($tr['audio_derivative_id'])?($derivativeMap[(int)$tr['audio_derivative_id']]??null):null;$sel->execute([$fid,$textDid]);$tid=(int)($sel->fetchColumn()?:0);if($tid)$upd->execute([(int)$root['id'],(string)$tr['source_hash'],$audioDid,(string)$tr['provider'],$tr['model']??null,$tr['language']??null,$tr['start_seconds']??null,$tr['end_seconds']??null,(string)$tr['full_text'],$tid]);else{$ins->execute([$fid,(int)$root['id'],(string)$tr['source_hash'],$audioDid,$textDid,(string)$tr['provider'],$tr['model']??null,$tr['language']??null,$tr['start_seconds']??null,$tr['end_seconds']??null,(string)$tr['full_text'],(string)($tr['created_at']??date('Y-m-d H:i:s'))]);$tid=(int)$pdo->lastInsertId();}$delSeg->execute([$tid]);foreach($tr['segments']??[] as $seg)$insSeg->execute([$tid,(int)$seg['sort_order'],$seg['start_seconds']??0,$seg['end_seconds']??0,(string)$seg['segment_text']]);foreach($tr['translations']??[] as $t){$variant=(string)($t['variant_key']??'');if($variant==='')continue;$selTrans->execute([$tid,$variant]);$trid=(int)($selTrans->fetchColumn()?:0);if($trid)$updTrans->execute([(string)$t['provider'],$t['model']??null,$t['source_language']??null,(string)$t['target_language'],(string)($t['translation_type']??'machine'),$t['custom_name']??null,(string)$t['full_text'],(string)($t['updated_at']??date('Y-m-d H:i:s')),$trid]);else{$insTrans->execute([$tid,(string)$t['provider'],$t['model']??null,$t['source_language']??null,(string)$t['target_language'],(string)($t['translation_type']??'machine'),$t['custom_name']??null,$variant,(string)$t['full_text'],(string)($t['created_at']??date('Y-m-d H:i:s')),(string)($t['updated_at']??date('Y-m-d H:i:s'))]);$trid=(int)$pdo->lastInsertId();}$delTransSeg->execute([$trid]);foreach($t['segments']??[] as $seg)$insTransSeg->execute([$trid,(int)$seg['sort_order'],$seg['start_seconds']??null,$seg['end_seconds']??null,(string)$seg['segment_text']]);}$count++;}
    return $count;
}

function lt_import_merges(array $root,array $items,array $fileMap,string $pathPrefix = ''):int
{
    $pdo=db();$count=0;
    $sel=$pdo->prepare('SELECT id FROM video_merges WHERE output_library_file_id=? LIMIT 1');
    $ins=$pdo->prepare('INSERT INTO video_merges (root_id,output_library_file_id,output_file_hash,output_name,created_at) VALUES (?,?,?,?,?)');
    $upd=$pdo->prepare('UPDATE video_merges SET root_id=?,output_file_hash=?,output_name=? WHERE id=?');
    $delSrc=$pdo->prepare('DELETE FROM video_merge_sources WHERE merge_id=?');
    $insSrc=$pdo->prepare('INSERT INTO video_merge_sources (merge_id,source_order,source_library_file_id,source_file_hash,source_file_name,source_relative_path) VALUES (?,?,?,?,?,?)');
    foreach($items as $m){$out=$fileMap[(int)($m['output_library_file_id']??0)]??0;if(!$out)continue;$sel->execute([$out]);$mid=(int)($sel->fetchColumn()?:0);if($mid)$upd->execute([(int)$root['id'],(string)$m['output_file_hash'],(string)$m['output_name'],$mid]);else{$ins->execute([(int)$root['id'],$out,(string)$m['output_file_hash'],(string)$m['output_name'],(string)($m['created_at']??date('Y-m-d H:i:s'))]);$mid=(int)$pdo->lastInsertId();}$delSrc->execute([$mid]);foreach($m['sources']??[] as $src){$srcId=null;if(isset($src['source_library_file_id']))$srcId=$fileMap[(int)$src['source_library_file_id']]??null;$insSrc->execute([$mid,(int)$src['source_order'],$srcId,(string)$src['source_file_hash'],(string)$src['source_file_name'],((string)($src['source_relative_path'] ?? '') !== '' ? lt_apply_import_prefix(lt_import_path_prefix($pathPrefix),(string)$src['source_relative_path']) : '')]);}$count++;}
    return $count;
}

function lt_import_promoted(array $root,array $items,array $fileMap):int
{
    $pdo=db();$count=0;$sel=$pdo->prepare('SELECT id FROM promoted_clips WHERE promoted_library_file_id=? LIMIT 1');$ins=$pdo->prepare('INSERT INTO promoted_clips (root_id,source_library_file_id,promoted_library_file_id,source_hash,promoted_hash,original_clip_name,created_at) VALUES (?,?,?,?,?,?,?)');$upd=$pdo->prepare('UPDATE promoted_clips SET root_id=?,source_library_file_id=?,source_hash=?,promoted_hash=?,original_clip_name=? WHERE id=?');
    foreach($items as $p){$src=$fileMap[(int)($p['source_library_file_id']??0)]??0;$dst=$fileMap[(int)($p['promoted_library_file_id']??0)]??0;if(!$src||!$dst)continue;$sel->execute([$dst]);$id=(int)($sel->fetchColumn()?:0);if($id)$upd->execute([(int)$root['id'],$src,(string)$p['source_hash'],(string)$p['promoted_hash'],(string)$p['original_clip_name'],$id]);else$ins->execute([(int)$root['id'],$src,$dst,(string)$p['source_hash'],(string)$p['promoted_hash'],(string)$p['original_clip_name'],(string)($p['created_at']??date('Y-m-d H:i:s'))]);$count++;}
    return $count;
}

function lt_import_library(string $rootPath,string $zipPath,string $pathPrefix = ''):array
{
    lt_require_zip();lt_ensure_schema();@set_time_limit(0);
    $pathPrefix=lt_import_path_prefix($pathPrefix);
    $root=li_resolve_root($rootPath,true);
    if(!is_file($zipPath)||!is_readable($zipPath))throw new RuntimeException('ZIP-файл импорта недоступен.');
    $zip=new ZipArchive();$open=$zip->open($zipPath);if($open!==true)throw new RuntimeException('Не удалось открыть ZIP-архив.');
    try{
        lt_validate_archive($zip);
        $data=lt_zip_json($zip,'database.json');
        if(($data['format']??'')!=='solanace-library-export')throw new RuntimeException('Некорректная база в архиве Solanace.');
        $cacheCount=lt_restore_cache($zip,$root);
        $pdo=db();$pdo->beginTransaction();
        try{
            [$fileMap,$mappedRows,$missing]=lt_map_or_create_files($root,$data['files']??[],$pathPrefix);
            $dirCount=lt_import_directories($root,$data['directories']??[],$pathPrefix);
            $categoryMap=lt_import_categories($root,$data['categories']??[]);
            $catAssign=lt_import_category_assignments($data['category_assignments']??[],$fileMap,$categoryMap);
            [$cardCount,$imageCount]=lt_import_cards($zip,$data['cards']??[],$mappedRows);
            $shotCount=lt_import_screenshots($root,$data['screenshot_sets']??[],$data['screenshots']??[],$mappedRows);
            [$derivativeMap,$derivativeCount]=lt_import_derivatives($root,$data['derivatives']??[],$fileMap);
            $transcriptCount=lt_import_transcripts($root,$data['transcripts']??[],$fileMap,$derivativeMap);
            $mergeCount=lt_import_merges($root,$data['merges']??[],$fileMap,$pathPrefix);
            $promotedCount=lt_import_promoted($root,$data['promoted_clips']??[],$fileMap);
            $pdo->prepare('UPDATE library_roots SET last_refresh_at=NOW() WHERE id=?')->execute([(int)$root['id']]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        li_write_marker((string)$root['root_path'],(string)$root['library_uid']);
        return [
            'mapped_files'=>count($fileMap),'missing_files'=>$missing,'path_prefix'=>$pathPrefix,'directories'=>$dirCount,'categories'=>count($categoryMap),'category_assignments'=>$catAssign,
            'cards'=>$cardCount,'attachments'=>$imageCount,'cache_files'=>$cacheCount,'screenshots'=>$shotCount,'derivatives'=>$derivativeCount,'transcripts'=>$transcriptCount,'merges'=>$mergeCount,'promoted_links'=>$promotedCount,
        ];
    }finally{$zip->close();}
}
