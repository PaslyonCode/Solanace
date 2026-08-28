<?php

declare(strict_types=1);

/**
 * Lightweight XLSX/CSV reader for small metadata utilities.
 * No Composer packages are required.
 */
final class MetadataSpreadsheetReader
{
    /** @return array<int, array<int, string>> */
    public static function read(string $path, string $originalName): array
    {
        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION), 'UTF-8');

        return match ($extension) {
            'xlsx' => self::readXlsx($path),
            'csv' => self::readCsv($path),
            'xls' => throw new RuntimeException('Старый формат .xls не поддерживается. Сохраните файл как .xlsx.'),
            default => throw new RuntimeException('Поддерживаются файлы .xlsx и .csv.'),
        };
    }

    /** @return array<int, array<int, string>> */
    private static function readXlsx(string $path): array
    {
        if (!class_exists('SimpleXMLElement')) {
            throw new RuntimeException('В PHP не включено расширение SimpleXML. В Laragon включите extension=simplexml.');
        }

        $archive = new MetadataOfficeArchive($path);
        try {
            $sharedStrings = self::readSharedStrings($archive);
            $sheetPaths = self::worksheetPaths($archive);
            if (!$sheetPaths) {
                $sheetPaths = ['xl/worksheets/sheet1.xml'];
            }

            $readableSheets = 0;
            foreach ($sheetPaths as $sheetPath) {
                $sheetXml = $archive->get($sheetPath);
                if ($sheetXml === null) continue;
                $readableSheets++;

                $rows = self::parseWorksheet($sheetXml, $sharedStrings);
                if (self::rowsContainValues($rows)) {
                    return $rows;
                }
            }

            if ($readableSheets === 0) {
                throw new RuntimeException('В XLSX не найден ни один доступный рабочий лист.');
            }

            return [];
        } finally {
            $archive->close();
        }
    }

    /**
     * Reads a worksheet without depending on a particular OOXML namespace.
     * This supports both transitional and strict XLSX variants.
     *
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private static function parseWorksheet(string $contents, array $sharedStrings): array
    {
        $xml = self::loadXml($contents, 'рабочий лист XLSX');
        $rows = [];

        $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
        foreach ($rowNodes as $rowNode) {
            $row = [];
            $fallbackColumn = 0;
            $cellNodes = $rowNode->xpath('./*[local-name()="c"]') ?: [];

            foreach ($cellNodes as $cell) {
                $reference = (string)($cell['r'] ?? '');
                $columnIndex = $reference !== ''
                    ? self::columnIndexFromReference($reference)
                    : $fallbackColumn;
                $fallbackColumn = max($fallbackColumn + 1, $columnIndex + 1);

                $row[$columnIndex] = self::cleanCell(
                    self::xlsxCellValue($cell, $sharedStrings)
                );
            }

            if (!$row) continue;

            ksort($row);
            $max = max(array_keys($row));
            $normalized = [];
            for ($i = 0; $i <= $max; $i++) {
                $normalized[$i] = $row[$i] ?? '';
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /** @param array<int, string> $sharedStrings */
    private static function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string)($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $inlineNodes = $cell->xpath('./*[local-name()="is"]') ?: [];
            return isset($inlineNodes[0]) ? self::richText($inlineNodes[0]) : '';
        }

        $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
        $raw = isset($valueNodes[0]) ? (string)$valueNodes[0] : '';

        return match ($type) {
            's' => $sharedStrings[(int)$raw] ?? '',
            'b' => $raw === '1' ? '1' : '0',
            default => $raw,
        };
    }

    /** @return array<int, string> */
    private static function readSharedStrings(MetadataOfficeArchive $archive): array
    {
        $contents = $archive->get('xl/sharedStrings.xml');
        if ($contents === null) return [];

        $xml = self::loadXml($contents, 'таблица строк XLSX');
        $values = [];
        foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $values[] = self::cleanCell(self::richText($item));
        }
        return $values;
    }

    /** @return array<int, string> */
    private static function worksheetPaths(MetadataOfficeArchive $archive): array
    {
        $workbookContents = $archive->get('xl/workbook.xml');
        $relationsContents = $archive->get('xl/_rels/workbook.xml.rels');
        if ($workbookContents === null || $relationsContents === null) {
            return ['xl/worksheets/sheet1.xml'];
        }

        $workbook = self::loadXml($workbookContents, 'книга XLSX');
        $relations = self::loadXml($relationsContents, 'связи книги XLSX');

        $targetsById = [];
        foreach ($relations->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) {
            $id = self::attributeByLocalName($relation, 'Id');
            $target = self::attributeByLocalName($relation, 'Target');
            if ($id === '' || $target === '') continue;
            $targetsById[$id] = self::resolveArchivePath('xl/workbook.xml', $target);
        }

        $paths = [];
        foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheet) {
            $relationshipId = self::attributeByLocalName($sheet, 'id');
            if ($relationshipId === '' || !isset($targetsById[$relationshipId])) continue;
            $path = $targetsById[$relationshipId];
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths ?: ['xl/worksheets/sheet1.xml'];
    }

    private static function attributeByLocalName(SimpleXMLElement $node, string $wanted): string
    {
        foreach ($node->attributes() as $name => $value) {
            if (strcasecmp((string)$name, $wanted) === 0) return (string)$value;
        }

        foreach ($node->getNamespaces(true) as $namespace) {
            foreach ($node->attributes($namespace) as $name => $value) {
                if (strcasecmp((string)$name, $wanted) === 0) return (string)$value;
            }
        }

        return '';
    }

    private static function resolveArchivePath(string $sourcePath, string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        if ($target === '') return '';

        if (str_starts_with($target, '/')) {
            $combined = ltrim($target, '/');
        } else {
            $baseDir = str_replace('\\', '/', dirname($sourcePath));
            $combined = ($baseDir === '.' ? '' : $baseDir . '/') . $target;
        }

        $parts = [];
        foreach (explode('/', $combined) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function richText(?SimpleXMLElement $node): string
    {
        if ($node === null) return '';
        $parts = [];
        foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $textNode) {
            $parts[] = (string)$textNode;
        }
        return implode('', $parts);
    }

    private static function loadXml(string $contents, string $description): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                throw new RuntimeException('Не удалось прочитать ' . $description . '.');
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function columnIndexFromReference(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) return 0;
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    /** @param array<int, array<int, string>> $rows */
    private static function rowsContainValues(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (trim((string)$value) !== '') return true;
            }
        }
        return false;
    }

    /** @return array<int, array<int, string>> */
    private static function readCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Не удалось прочитать CSV-файл.');

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $detected = mb_detect_encoding($contents, ['Windows-1251', 'UTF-8', 'ISO-8859-1'], true);
            if ($detected) $contents = mb_convert_encoding($contents, 'UTF-8', $detected);
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $candidates = [";", ",", "\t"];
        $delimiter = ';';
        $maxCount = -1;
        foreach ($candidates as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $maxCount) {
                $maxCount = $count;
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'w+b');
        if (!$stream) throw new RuntimeException('Не удалось открыть временный поток CSV.');
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            $rows[] = array_map(static fn($value) => self::cleanCell((string)$value), $row);
        }
        fclose($stream);
        return $rows;
    }

    private static function cleanCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return trim($value);
    }
}

final class MetadataOfficeArchive
{
    private ?ZipArchive $zip = null;
    private ?PharData $phar = null;

    public function __construct(string $path)
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $this->zip = $zip;
                return;
            }
        }

        if (class_exists('PharData')) {
            try {
                $this->phar = new PharData($path);
                return;
            } catch (Throwable) {
                // Fall through to a clear error below.
            }
        }

        throw new RuntimeException('PHP не может открыть XLSX. В Laragon включите расширение ZipArchive (extension=zip).');
    }

    public function get(string $entry): ?string
    {
        $entry = ltrim(str_replace('\\', '/', $entry), '/');
        if ($this->zip !== null) {
            $contents = $this->zip->getFromName($entry);
            return $contents === false ? null : $contents;
        }

        if ($this->phar !== null) {
            try {
                if (!isset($this->phar[$entry])) return null;
                return $this->phar[$entry]->getContent();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public function close(): void
    {
        if ($this->zip !== null) $this->zip->close();
        $this->zip = null;
        $this->phar = null;
    }
}
