<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/library_identity.php';

/**
 * Categories are scoped to a cached library root.  The card itself remains
 * hash-based (title/note survive moves), while category assignment belongs to
 * the concrete library_file row so the same hash may be categorized
 * differently in different libraries.
 */
function lc_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    li_ensure_schema();
    $pdo = db();

    // Old installations had a global categories(name UNIQUE) table.
    $column = $pdo->query("SHOW COLUMNS FROM categories LIKE 'root_id'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN root_id INT UNSIGNED NULL AFTER id");
    }

    // Remove the old global UNIQUE(name) index before duplicating category
    // names for several roots.  We discover it instead of assuming its name.
    $indexRows = $pdo->query('SHOW INDEX FROM categories')->fetchAll();
    $uniqueIndexes = [];
    foreach ($indexRows as $row) {
        if ((int)$row['Non_unique'] !== 0 || (string)$row['Key_name'] === 'PRIMARY') continue;
        $key = (string)$row['Key_name'];
        $seq = (int)$row['Seq_in_index'];
        $uniqueIndexes[$key][$seq] = (string)$row['Column_name'];
    }
    foreach ($uniqueIndexes as $key => $columns) {
        ksort($columns);
        if (array_values($columns) === ['name']) {
            $safeKey = str_replace('`', '``', $key);
            $pdo->exec("ALTER TABLE categories DROP INDEX `{$safeKey}`");
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_file_categories (
            library_file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_library_file_categories_category (category_id),
            CONSTRAINT fk_library_file_categories_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_library_file_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Migrate all legacy global categories.  Every old category is copied to
    // every currently known library so switching to scoped categories does not
    // unexpectedly hide an existing category name.  Actual file assignments
    // are transferred through file_hash into library_file_categories.
    $legacy = $pdo->query("SELECT id, name FROM categories WHERE root_id IS NULL ORDER BY id")->fetchAll();
    if ($legacy) {
        $roots = $pdo->query('SELECT id FROM library_roots ORDER BY id')->fetchAll();
        $pdo->beginTransaction();
        try {
            $findScoped = $pdo->prepare('SELECT id FROM categories WHERE root_id = ? AND name = ? LIMIT 1');
            $insertScoped = $pdo->prepare('INSERT INTO categories (root_id, name) VALUES (?, ?)');
            $findFiles = $pdo->prepare(
                'SELECT lf.id
                 FROM library_files lf
                 INNER JOIN file_cards fc ON fc.file_hash = lf.file_hash
                 WHERE lf.root_id = ? AND fc.category_id = ?'
            );
            $setMapping = $pdo->prepare(
                'INSERT INTO library_file_categories (library_file_id, category_id)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)'
            );

            foreach ($roots as $root) {
                $rootId = (int)$root['id'];
                foreach ($legacy as $category) {
                    $name = (string)$category['name'];
                    $findScoped->execute([$rootId, $name]);
                    $scopedId = (int)$findScoped->fetchColumn();
                    if ($scopedId <= 0) {
                        $insertScoped->execute([$rootId, $name]);
                        $scopedId = (int)$pdo->lastInsertId();
                    }

                    $findFiles->execute([$rootId, (int)$category['id']]);
                    foreach ($findFiles->fetchAll() as $file) {
                        $setMapping->execute([(int)$file['id'], $scopedId]);
                    }
                }
            }

            // category_id in file_cards is now legacy-only.  Clearing it lets
            // us safely remove the old global category rows while preserving
            // titles, notes and images in hash-based cards.
            $pdo->exec('UPDATE file_cards SET category_id = NULL WHERE category_id IS NOT NULL');
            $pdo->exec('DELETE FROM categories WHERE root_id IS NULL');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // Any unused legacy category from a database with no roots has no sensible
    // scope.  It will be impossible to use until a library exists, so leave it
    // nullable for now.  Once no global rows remain, enforce scoped rows.
    $nullCount = (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE root_id IS NULL')->fetchColumn();
    if ($nullCount === 0) {
        $rootColumn = $pdo->query("SHOW COLUMNS FROM categories LIKE 'root_id'")->fetch();
        if ($rootColumn && strtoupper((string)$rootColumn['Null']) === 'YES') {
            $pdo->exec('ALTER TABLE categories MODIFY root_id INT UNSIGNED NOT NULL');
        }
    }

    $composite = $pdo->query("SHOW INDEX FROM categories WHERE Key_name = 'uq_categories_root_name'")->fetch();
    if (!$composite) {
        $pdo->exec('CREATE UNIQUE INDEX uq_categories_root_name ON categories (root_id, name)');
    }
    $rootIndex = $pdo->query("SHOW INDEX FROM categories WHERE Key_name = 'idx_categories_root'")->fetch();
    if (!$rootIndex) {
        $pdo->exec('CREATE INDEX idx_categories_root ON categories (root_id)');
    }

    $done = true;
}

function lc_categories_for_root(int $rootId): array
{
    lc_ensure_schema();
    $stmt = db()->prepare('SELECT id, name FROM categories WHERE root_id = ? ORDER BY name');
    $stmt->execute([$rootId]);
    return $stmt->fetchAll();
}

function lc_category_belongs_to_root(int $categoryId, int $rootId): bool
{
    if ($categoryId <= 0 || $rootId <= 0) return false;
    lc_ensure_schema();
    $stmt = db()->prepare('SELECT 1 FROM categories WHERE id = ? AND root_id = ? LIMIT 1');
    $stmt->execute([$categoryId, $rootId]);
    return (bool)$stmt->fetchColumn();
}

function lc_category_id_for_file(int $libraryFileId): ?int
{
    if ($libraryFileId <= 0) return null;
    lc_ensure_schema();
    $stmt = db()->prepare('SELECT category_id FROM library_file_categories WHERE library_file_id = ? LIMIT 1');
    $stmt->execute([$libraryFileId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function lc_set_file_category(int $libraryFileId, int $rootId, ?int $categoryId): void
{
    lc_ensure_schema();
    if ($categoryId === null || $categoryId <= 0) {
        db()->prepare('DELETE FROM library_file_categories WHERE library_file_id = ?')->execute([$libraryFileId]);
        return;
    }
    if (!lc_category_belongs_to_root($categoryId, $rootId)) {
        throw new RuntimeException('Выбранная категория относится к другой библиотеке.');
    }
    db()->prepare(
        'INSERT INTO library_file_categories (library_file_id, category_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)'
    )->execute([$libraryFileId, $categoryId]);
}
