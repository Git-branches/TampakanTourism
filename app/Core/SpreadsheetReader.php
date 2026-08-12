<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — reading a CSV or XLSX without Composer                 Feature 2
 * -----------------------------------------------------------------------------
 *  This project deploys to cPanel and has no dependency manager, so
 *  PhpSpreadsheet is not available. XLSX is a ZIP of XML, and ZipArchive and
 *  SimpleXML are both present, so the format is readable directly.
 *
 *  This reads spreadsheets. It does not write them, does not evaluate formulas,
 *  and does not attempt styling — a formula cell yields its last cached value,
 *  which is what a manager exporting a tourist list actually needs.
 *
 *  SECURITY. An uploaded spreadsheet is hostile input in two specific ways:
 *
 *    XXE       the XML inside an XLSX can declare an external entity that
 *              reads a file off the server, or calls out to a host. Parsed
 *              with LIBXML_NONET and no entity substitution, and the DOCTYPE
 *              is rejected outright before parsing.
 *
 *    Zip bomb  a 40 KB archive can declare gigabytes of contents. Entry sizes
 *              are checked before anything is extracted, and nothing is ever
 *              written to disk — entries are read into memory under a cap.
 *
 *  Row and column ceilings exist for the same reason: a spreadsheet claiming a
 *  million rows should be refused, not honoured until the server runs out of
 *  memory.
 * =============================================================================
 */
final class SpreadsheetReader
{
    public const MAX_ROWS    = 5000;
    public const MAX_COLUMNS = 32;

    /** Largest single entry read out of an XLSX, uncompressed. */
    private const MAX_ENTRY_BYTES = 16 * 1024 * 1024;

    /** @var array<int, string> */
    private array $errors = [];

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors !== [] ? reset($this->errors) : null;
    }

    /**
     * Reads a file into rows of strings. The first row is whatever the file
     * has first — the caller decides whether it is a header.
     *
     * @return array<int, array<int, string>>|null
     */
    public function read(string $path, string $extension): ?array
    {
        $this->errors = [];

        return match (strtolower($extension)) {
            'csv'         => $this->readCsv($path),
            'xlsx', 'xlsm' => $this->readXlsx($path),
            default       => $this->fail('Only CSV and XLSX files can be read.'),
        };
    }

    /**
     * The file's real type, by its own bytes.
     *
     * A CSV has no magic number, so it is identified by exclusion: if it is not
     * a ZIP and it is valid UTF-8 text, it is treated as CSV. The extension is
     * never the deciding factor — it is attacker input.
     *
     * @return string|null 'csv' | 'xlsx'
     */
    public static function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        /* PK\x03\x04 — a ZIP, which is what an XLSX is. */
        if (str_starts_with($head, "PK\x03\x04")) {
            return 'xlsx';
        }

        $sample = (string) file_get_contents($path, false, null, 0, 8192);

        if ($sample === '' || !mb_check_encoding($sample, 'UTF-8')) {
            return null;
        }

        /* A NUL byte in the first 8 KB means a binary file wearing a .csv
           extension — old .xls, for instance, which this cannot read. */
        return str_contains($sample, "\0") ? null : 'csv';
    }

    // -------------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, string>>|null */
    private function readCsv(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $this->fail('That file could not be opened.');
        }

        /* Excel on Windows writes a UTF-8 BOM. Left in place it becomes part of
           the first header cell, and the column "Name" silently stops matching. */
        $bom = (string) fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = $this->sniffDelimiter($path, $bom !== "\xEF\xBB\xBF" ? 0 : 3);

        $rows = [];

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            /* fgetcsv yields [null] for a blank line. */
            if ($cells === [null]) {
                continue;
            }

            $rows[] = array_map(
                static fn ($c): string => trim(self::toUtf8((string) $c)),
                array_slice($cells, 0, self::MAX_COLUMNS)
            );

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                return $this->fail('That file has more than ' . number_format(self::MAX_ROWS) . ' rows. Please split it.');
            }
        }

        fclose($handle);

        return $rows === [] ? $this->fail('That file is empty.') : $rows;
    }

    /**
     * Comma or semicolon.
     *
     * Excel in a locale that uses the comma as a decimal separator writes
     * semicolon-delimited CSV. Guessing wrong yields one enormous column and an
     * error message that blames the manager for a file Excel produced.
     */
    private function sniffDelimiter(string $path, int $offset): string
    {
        $sample = (string) file_get_contents($path, false, null, $offset, 4096);
        $line   = strtok($sample, "\r\n");

        if ($line === false) {
            return ',';
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    // -------------------------------------------------------------------------
    // XLSX
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, string>>|null */
    private function readXlsx(string $path): ?array
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            return $this->fail('That XLSX file could not be opened. It may be damaged.');
        }

        $sheetXml = $this->entry($zip, 'xl/worksheets/sheet1.xml');

        if ($sheetXml === null) {
            /* Some producers name the first sheet differently. Take whichever
               worksheet comes first alphabetically rather than giving up. */
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                    $sheetXml = $this->entry($zip, $name);
                    break;
                }
            }
        }

        if ($sheetXml === null) {
            $zip->close();
            return $this->fail('No worksheet was found inside that XLSX file.');
        }

        $shared = $this->sharedStrings($zip);
        $zip->close();

        if ($shared === null) {
            return null;
        }

        return $this->parseSheet($sheetXml, $shared);
    }

    /** @return array<int, string>|null */
    private function sharedStrings(\ZipArchive $zip): ?array
    {
        $xml = $this->entry($zip, 'xl/sharedStrings.xml');

        /* A sheet of nothing but numbers has no shared strings table. */
        if ($xml === null) {
            return [];
        }

        $doc = $this->parseXml($xml);

        if ($doc === null) {
            return null;
        }

        $strings = [];

        foreach ($doc->si as $item) {
            /* A cell with mixed formatting is split across several <t> runs;
               concatenating them is what reassembles the actual text. */
            $text = '';

            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  array<int, string> $shared
     * @return array<int, array<int, string>>|null
     */
    private function parseSheet(string $xml, array $shared): ?array
    {
        $doc = $this->parseXml($xml);

        if ($doc === null) {
            return null;
        }

        $rows = [];

        foreach ($doc->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c ?? [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $index     = $reference !== '' ? self::columnIndex($reference) : count($cells);

                if ($index >= self::MAX_COLUMNS) {
                    continue;
                }

                $cells[$index] = trim($this->cellValue($cell, $shared));
            }

            if ($cells === []) {
                $rows[] = [];
                continue;
            }

            /* Gaps are real: an empty Address cell must stay in position or
               every column after it shifts left by one. */
            $width  = max(array_keys($cells)) + 1;
            $padded = [];

            for ($i = 0; $i < $width; $i++) {
                $padded[$i] = $cells[$i] ?? '';
            }

            $rows[] = $padded;

            if (count($rows) > self::MAX_ROWS) {
                return $this->fail('That file has more than ' . number_format(self::MAX_ROWS) . ' rows. Please split it.');
            }
        }

        return $rows === [] ? $this->fail('That worksheet is empty.') : $rows;
    }

    /** @param array<int, string> $shared */
    private function cellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $index = (int) $cell->v;
            return $shared[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $text = '';

            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }

            return $text;
        }

        /* A formula cell carries its cached result in <v>, which is the value
           the manager saw in Excel. Formulas are never evaluated here. */
        return (string) ($cell->v ?? '');
    }

    /** "BC12" -> 54. Column letters are base-26 with no zero. */
    private static function columnIndex(string $reference): int
    {
        $letters = strtoupper(preg_replace('/\d+/', '', $reference) ?? '');
        $index   = 0;

        for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    /** Reads one archive entry into memory, refusing anything oversized. */
    private function entry(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            return null;
        }

        /* Checked BEFORE reading: this is the zip-bomb guard. A small archive
           declaring a gigabyte of contents is refused rather than expanded. */
        if (($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
            $this->errors[] = 'That XLSX file contains an oversized part and was not read.';
            return null;
        }

        $contents = $zip->getFromName($name);

        return $contents === false ? null : $contents;
    }

    /**
     * Parses XML with external entities disabled.
     *
     * LIBXML_NONET blocks network fetches and LIBXML_NOENT is deliberately NOT
     * passed, so entities are not substituted. The DOCTYPE check rejects the
     * declaration before the parser ever sees it, which is the belt to that
     * pair of braces.
     */
    private function parseXml(string $xml): ?\SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $this->errors[] = 'That file contains an XML document type declaration and was not read.';
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            $this->errors[] = 'That file could not be read as a spreadsheet.';
            return null;
        }

        return $doc;
    }

    private static function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        /* Excel on Windows still writes CP1252 when saving "CSV (Comma
           delimited)". Left alone, "Peña" arrives mangled. */
        return (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function fail(string $message): null
    {
        $this->errors[] = $message;

        return null;
    }
}
