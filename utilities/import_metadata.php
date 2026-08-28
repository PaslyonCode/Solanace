<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/library_identity.php';
require_once dirname(__DIR__) . '/library_categories.php';
auth_require_json();
require_once __DIR__ . '/xlsx_reader.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Разрешен только POST-запрос.'], 405);
    }

    $rootPath = metadataNormalizeRoot((string)($_POST['root'] ?? ''));
    if ($rootPath === '') throw new RuntimeException('Сначала выберите корневую папку каталога.');

    $upload = $_FILES['metadata_file'] ?? null;
    if (!$upload || !is_array($upload)) throw new RuntimeException('Excel-файл не выбран.');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(metadataUploadError((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE)));
    }
    if ((int)($upload['size'] ?? 0) > 25 * 1024 * 1024) {
        throw new RuntimeException('Файл слишком большой. Максимальный размер — 25 МБ.');
    }

    $overwriteBlanks = isset($_POST['overwrite_blanks']) && (string)$_POST['overwrite_blanks'] === '1';
    $rows = MetadataSpreadsheetReader::read((string)$upload['tmp_name'], (string)$upload['name']);
    if (!$rows) throw new RuntimeException('В XLSX не найдено заполненных ячеек ни на одном листе.');

    $result = metadataImportRows($rootPath, $rows, $overwriteBlanks);
    json_response(['ok' => true, 'result' => $result]);
} catch (Throwable $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}

function metadataNormalizeRoot(string $path): string
{
    $path = trim($path, " \t\n\r\0\x0B\"'");
    if ($path === '') return '';
    $real = realpath($path);
    $path = $real !== false ? $real : $path;
    if (preg_match('/^[A-Za-z]:[\\\/]*$/', $path)) {
        return strtoupper($path[0]) . ':\\';
    }
    return rtrim($path, "\\/");
}

function metadataPathKey(string $path): string
{
    $path = metadataNormalizeRoot($path);
    $canonical = str_replace('/', '\\', $path);
    if (DIRECTORY_SEPARATOR === '\\') $canonical = mb_strtolower($canonical, 'UTF-8');
    return sha1($canonical);
}

function metadataUploadError(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер загрузки PHP.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью.',
        UPLOAD_ERR_NO_FILE => 'Excel-файл не выбран.',
        UPLOAD_ERR_NO_TMP_DIR => 'В PHP не настроена временная папка.',
        UPLOAD_ERR_CANT_WRITE => 'PHP не смог записать временный файл.',
        UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP.',
        default => 'Не удалось загрузить файл.',
    };
}

/**
 * @param array<int, array<int, string>> $rows
 * @return array<string, mixed>
 */
function metadataImportRows(string $rootPath, array $rows, bool $overwriteBlanks): array
{
    [$headerRowIndex, $columns] = metadataFindHeader($rows);
    if (!isset($columns['filename'])) {
        throw new RuntimeException('Не найдена колонка «Название файла».');
    }

    $pdo = db();
    try {
        $root = li_resolve_root($rootPath, false);
    } catch (Throwable $e) {
        throw new RuntimeException('Эта папка еще не добавлена в кэш. Сначала откройте ее в каталоге или нажмите «Обновить кэш».');
    }

    $fileStmt = $pdo->prepare(
        'SELECT id, root_id, relative_path, file_path, file_name, file_hash
         FROM library_files WHERE root_id = ? ORDER BY relative_path'
    );
    $fileStmt->execute([(int)$root['id']]);
    $files = $fileStmt->fetchAll();
    if (!$files) throw new RuntimeException('В кэше выбранной папки нет видеофайлов.');

    $indexes = metadataBuildFileIndexes($files);
    lc_ensure_schema();
    $categoryCache = [];
    $catStmt = $pdo->prepare('SELECT id, name FROM categories WHERE root_id = ? ORDER BY name');
    $catStmt->execute([(int)$root['id']]);
    foreach ($catStmt->fetchAll() as $category) {
        $categoryCache[metadataLower((string)$category['name'])] = (int)$category['id'];
    }

    $summary = [
        'table_rows' => 0,
        'updated' => 0,
        'created_cards' => 0,
        'unchanged' => 0,
        'not_found' => 0,
        'ambiguous' => 0,
        'skipped' => 0,
        'errors' => 0,
        'categories_created' => 0,
    ];
    $details = [];

    $findCard = $pdo->prepare('SELECT * FROM file_cards WHERE file_hash = ? LIMIT 1');
    $insertCard = $pdo->prepare(
        'INSERT INTO file_cards (file_path, file_hash, custom_title, note, category_id)
         VALUES (?, ?, ?, ?, NULL)'
    );
    $updateCard = $pdo->prepare(
        'UPDATE file_cards SET file_path = ?, custom_title = ?, note = ?, category_id = NULL WHERE id = ?'
    );
    $insertCategory = $pdo->prepare('INSERT IGNORE INTO categories (root_id, name) VALUES (?, ?)');
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE root_id = ? AND name = ? LIMIT 1');
    $findFileCategory = $pdo->prepare('SELECT category_id FROM library_file_categories WHERE library_file_id = ? LIMIT 1');

    $pdo->beginTransaction();
    try {
        for ($rowIndex = $headerRowIndex + 1, $rowCount = count($rows); $rowIndex < $rowCount; $rowIndex++) {
            $row = $rows[$rowIndex];
            if (metadataRowIsEmpty($row)) continue;
            $summary['table_rows']++;

            $displayRow = $rowIndex + 1;
            $fileValue = metadataCell($row, $columns['filename']);
            if ($fileValue === '') {
                $summary['skipped']++;
                metadataDetail($details, $displayRow, '', 'skipped', 'Не указано название файла.');
                continue;
            }

            $match = metadataMatchFile($fileValue, $indexes);
            if ($match['status'] === 'not_found') {
                $summary['not_found']++;
                metadataDetail($details, $displayRow, $fileValue, 'not_found', 'Файл не найден в кэше выбранной папки.');
                continue;
            }
            if ($match['status'] === 'ambiguous') {
                $summary['ambiguous']++;
                metadataDetail(
                    $details,
                    $displayRow,
                    $fileValue,
                    'ambiguous',
                    'Найдено несколько файлов. Укажите относительный путь с подпапкой.',
                    $match['candidates'] ?? []
                );
                continue;
            }

            /** @var array<string, mixed> $file */
            $file = $match['file'];
            $hasTitleColumn = isset($columns['title']);
            $hasNoteColumn = isset($columns['note']);
            $hasCategoryColumn = isset($columns['category']);
            $titleValue = $hasTitleColumn ? metadataCell($row, $columns['title']) : '';
            $noteValue = $hasNoteColumn ? metadataCell($row, $columns['note']) : '';
            $categoryValue = $hasCategoryColumn ? metadataCell($row, $columns['category']) : '';

            $hasUsefulValue = $titleValue !== '' || $noteValue !== '' || $categoryValue !== '';
            if (!$hasUsefulValue && !$overwriteBlanks) {
                $summary['skipped']++;
                metadataDetail($details, $displayRow, $fileValue, 'skipped', 'Все поля метаданных пусты.');
                continue;
            }

            try {
                $findCard->execute([$file['file_hash']]);
                $card = $findCard->fetch() ?: null;
                $oldTitle = (string)($card['custom_title'] ?? '');
                $oldNote = (string)($card['note'] ?? '');
                $findFileCategory->execute([(int)$file['id']]);
                $oldCategoryValue = $findFileCategory->fetchColumn();
                $oldCategoryId = $oldCategoryValue !== false ? (int)$oldCategoryValue : null;

                $newTitle = $oldTitle;
                $newNote = $oldNote;
                $newCategoryId = $oldCategoryId;

                if ($hasTitleColumn && ($overwriteBlanks || $titleValue !== '')) {
                    $newTitle = mb_substr($titleValue, 0, 255, 'UTF-8');
                }
                if ($hasNoteColumn && ($overwriteBlanks || $noteValue !== '')) {
                    $newNote = $noteValue;
                }
                if ($hasCategoryColumn && ($overwriteBlanks || $categoryValue !== '')) {
                    if ($categoryValue === '') {
                        $newCategoryId = null;
                    } else {
                        $categoryName = mb_substr(trim($categoryValue), 0, 190, 'UTF-8');
                        $categoryKey = metadataLower($categoryName);
                        if (!isset($categoryCache[$categoryKey])) {
                            $insertCategory->execute([(int)$root['id'], $categoryName]);
                            $findCategory->execute([(int)$root['id'], $categoryName]);
                            $categoryId = (int)$findCategory->fetchColumn();
                            if ($categoryId <= 0) throw new RuntimeException('Не удалось создать категорию «' . $categoryName . '».');
                            $categoryCache[$categoryKey] = $categoryId;
                            $summary['categories_created']++;
                        }
                        $newCategoryId = $categoryCache[$categoryKey];
                    }
                }

                if (!$card) {
                    $insertCard->execute([
                        $file['file_path'],
                        $file['file_hash'],
                        $newTitle !== '' ? $newTitle : null,
                        $newNote !== '' ? $newNote : null,
                    ]);
                    lc_set_file_category((int)$file['id'], (int)$root['id'], $newCategoryId);
                    $summary['created_cards']++;
                    $summary['updated']++;
                    metadataDetail($details, $displayRow, $fileValue, 'updated', 'Карточка создана и метаданные добавлены.', [$file['relative_path']]);
                    continue;
                }

                $changed = (string)$card['file_path'] !== (string)$file['file_path']
                    || $oldTitle !== $newTitle
                    || $oldNote !== $newNote
                    || $oldCategoryId !== $newCategoryId;

                if ($changed) {
                    $updateCard->execute([
                        $file['file_path'],
                        $newTitle !== '' ? $newTitle : null,
                        $newNote !== '' ? $newNote : null,
                        (int)$card['id'],
                    ]);
                    lc_set_file_category((int)$file['id'], (int)$root['id'], $newCategoryId);
                    $summary['updated']++;
                    metadataDetail($details, $displayRow, $fileValue, 'updated', 'Метаданные обновлены.', [$file['relative_path']]);
                } else {
                    $summary['unchanged']++;
                    metadataDetail($details, $displayRow, $fileValue, 'unchanged', 'Изменений нет.', [$file['relative_path']]);
                }
            } catch (Throwable $rowError) {
                $summary['errors']++;
                metadataDetail($details, $displayRow, $fileValue, 'error', $rowError->getMessage());
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    return [
        'root' => $root['root_path'],
        'source_file' => basename((string)($_FILES['metadata_file']['name'] ?? '')),
        'overwrite_blanks' => $overwriteBlanks,
        'summary' => $summary,
        'details' => $details,
        'details_limited' => $summary['table_rows'] > count($details),
        'columns' => [
            'filename' => $columns['filename'] + 1,
            'title' => isset($columns['title']) ? $columns['title'] + 1 : null,
            'note' => isset($columns['note']) ? $columns['note'] + 1 : null,
            'category' => isset($columns['category']) ? $columns['category'] + 1 : null,
        ],
    ];
}

/** @return array{0:int,1:array<string,int>} */
function metadataFindHeader(array $rows): array
{
    $aliases = [
        'filename' => ['название файла', 'имя файла', 'файл', 'filename', 'file name', 'video file'],
        'title' => ['кастомный заголовок', 'пользовательский заголовок', 'заголовок', 'custom title', 'title'],
        'note' => ['заметка', 'примечание', 'описание', 'note', 'notes', 'description'],
        'category' => ['категория', 'category'],
    ];

    foreach (array_slice($rows, 0, 20, true) as $rowIndex => $row) {
        $columns = [];
        foreach ($row as $columnIndex => $value) {
            $header = metadataNormalizeHeader((string)$value);
            foreach ($aliases as $key => $variants) {
                if (!isset($columns[$key]) && in_array($header, $variants, true)) {
                    $columns[$key] = (int)$columnIndex;
                }
            }
        }
        if (isset($columns['filename'])) return [(int)$rowIndex, $columns];
    }

    throw new RuntimeException('Не удалось определить строку заголовков. Нужна колонка «Название файла».');
}

function metadataNormalizeHeader(string $value): string
{
    $value = metadataLower(trim($value));
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[\s_\-–—]+/u', ' ', $value) ?? $value;
    return trim($value, " \t\n\r\0\x0B:.;");
}

function metadataLower(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function metadataCell(array $row, int $column): string
{
    return trim((string)($row[$column] ?? ''));
}

function metadataRowIsEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') return false;
    }
    return true;
}

/** @param array<int, array<string, mixed>> $files */
function metadataBuildFileIndexes(array $files): array
{
    $indexes = ['relative' => [], 'absolute' => [], 'filename' => [], 'stem' => []];
    foreach ($files as $file) {
        $relative = metadataNormalizeLookupPath((string)$file['relative_path']);
        $absolute = metadataNormalizeLookupPath((string)$file['file_path']);
        $fileName = metadataLower((string)$file['file_name']);
        $stem = metadataLower(pathinfo((string)$file['file_name'], PATHINFO_FILENAME));
        $indexes['relative'][$relative][] = $file;
        $indexes['absolute'][$absolute][] = $file;
        $indexes['filename'][$fileName][] = $file;
        $indexes['stem'][$stem][] = $file;
    }
    return $indexes;
}

function metadataNormalizeLookupPath(string $value): string
{
    $value = trim($value, " \t\n\r\0\x0B\"'");
    $value = str_replace('\\', '/', $value);
    $value = preg_replace('~/+~', '/', $value) ?? $value;
    return metadataLower(trim($value, '/'));
}

/** @return array<string, mixed> */
function metadataMatchFile(string $input, array $indexes): array
{
    $normalizedPath = metadataNormalizeLookupPath($input);
    if ($normalizedPath === '') return ['status' => 'not_found'];

    if (str_contains($normalizedPath, '/')) {
        $candidates = $indexes['relative'][$normalizedPath] ?? $indexes['absolute'][$normalizedPath] ?? [];
        return metadataResolveCandidates($candidates);
    }

    $exact = $indexes['filename'][metadataLower($input)] ?? [];
    if ($exact) return metadataResolveCandidates($exact);

    $stem = metadataLower(pathinfo($input, PATHINFO_FILENAME));
    $candidates = $indexes['stem'][$stem] ?? [];
    return metadataResolveCandidates($candidates);
}

/** @param array<int, array<string, mixed>> $candidates */
function metadataResolveCandidates(array $candidates): array
{
    if (!$candidates) return ['status' => 'not_found'];
    if (count($candidates) === 1) return ['status' => 'matched', 'file' => $candidates[0]];
    return [
        'status' => 'ambiguous',
        'candidates' => array_slice(array_map(static fn($file) => (string)$file['relative_path'], $candidates), 0, 12),
    ];
}

function metadataDetail(array &$details, int $row, string $file, string $status, string $message, array $matches = []): void
{
    if (count($details) >= 500) return;
    $details[] = [
        'row' => $row,
        'file' => $file,
        'status' => $status,
        'message' => $message,
        'matches' => $matches,
    ];
}
