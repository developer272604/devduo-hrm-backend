<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads CSV and XLSX files as a stream of associative rows.
 *
 *   $reader = TabularReader::open($path, $originalName, ['max_rows' => 20000]);
 *   $reader->format();      // 'csv' | 'xlsx'
 *   $reader->headers();     // keys used by rows() (normalized snake_case unless normalize_headers=false)
 *   $reader->rawHeaders();  // header text exactly as written in the file (trimmed, de-duplicated)
 *   foreach ($reader->rows() as $rowNumber => $row) { ... }   // $rowNumber = position in the file (1 = first line)
 *   foreach ($reader->chunks(500) as $rows) { ... }            // [rowNumber => row] arrays, for bulk inserts
 *
 * Format is detected from the extension AND the content; a mismatch is refused with a clear message.
 * CSV: UTF-8 (BOM stripped), UTF-16 and Windows-1252 are transcoded; delimiter (, ; tab |) is sniffed.
 * XLSX: read-only, chunked through a read filter, so memory stays flat for large sheets; dates become
 * Y-m-d strings and formulas are never evaluated (the cached value is used). Blank rows are skipped.
 * Every cell is a trimmed string or null.
 */
final class TabularReader
{
    public const DEFAULTS = [
        'max_rows' => 50000,
        'max_bytes' => 26214400,            // 25 MB
        'max_columns' => 200,
        'max_uncompressed_bytes' => 209715200, // zip-bomb guard for xlsx
        'normalize_headers' => true,
        'delimiter' => null,                // force a CSV delimiter instead of sniffing
        'xlsx_chunk_rows' => 1000,
    ];

    private array $options;

    private string $csvPath;

    private ?string $tempPath = null;

    private int $bomLength = 0;

    private string $delimiter = ',';

    private string $sheetName = '';

    private int $headerRow = 1;

    /** @var array<int,string> */
    private array $rawHeaders = [];

    /** @var array<int,string> */
    private array $headers = [];

    private function __construct(private string $path, private string $format, array $options)
    {
        $this->options = $options;
        $this->csvPath = $path;
    }

    public function __destruct()
    {
        if ($this->tempPath && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    public static function open(string $path, ?string $originalName = null, array $options = []): self
    {
        $options += self::DEFAULTS;
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException('The file could not be read.');
        }
        $size = filesize($path);
        if ($size === 0) {
            throw new SpreadsheetException('The file is empty.', 'empty_file');
        }
        if ($size > $options['max_bytes']) {
            throw SpreadsheetException::tooLarge($options['max_bytes']);
        }
        $reader = new self($path, self::detectFormat($path, $originalName ?? $path, $options['max_uncompressed_bytes']), $options);
        $reader->prepare();

        return $reader;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function delimiter(): ?string
    {
        return $this->format === 'csv' ? $this->delimiter : null;
    }

    /** Keys of the arrays yielded by rows(). */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Header text as written in the file (trimmed, de-duplicated, blank → "Column N"). */
    public function rawHeaders(): array
    {
        return $this->rawHeaders;
    }

    /** snake_case a header: "Date of Birth" → "date_of_birth", "CF: Grade" → "cf:_grade" is avoided: "cf:grade". */
    public static function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = mb_strtolower(trim($header));
        $header = preg_replace('/\s*:\s*/u', ':', $header);
        $header = preg_replace('/[^\p{L}\p{N}:]+/u', '_', $header);

        return trim($header, '_');
    }

    /** @return \Generator<int,array<string,?string>> row number (position in the file, header counts) => values keyed by headers() */
    public function rows(): \Generator
    {
        return $this->format === 'csv' ? $this->csvRows() : $this->xlsxRows();
    }

    /** @return \Generator<int,array<int,array<string,?string>>> chunks of [rowNumber => row] */
    public function chunks(int $size = 500): \Generator
    {
        $buffer = [];
        foreach ($this->rows() as $number => $row) {
            $buffer[$number] = $row;
            if (count($buffer) >= $size) {
                yield $buffer;
                $buffer = [];
            }
        }
        if ($buffer) {
            yield $buffer;
        }
    }

    /** First rows, for previews. @return array<int,array<string,?string>> */
    public function sample(int $limit = 3): array
    {
        $out = [];
        foreach ($this->rows() as $number => $row) {
            $out[$number] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Number of data rows (reads the file once, enforcing the row limit). */
    public function count(): int
    {
        $count = 0;
        foreach ($this->rows() as $_) {
            $count++;
        }

        return $count;
    }

    // ---- detection -------------------------------------------------------------------------

    private static function detectFormat(string $path, string $name, int $maxUncompressed): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || $extension === 'xls') {
            throw new SpreadsheetException('Old Excel (.xls) files are not supported. Save the file as .xlsx or .csv and upload it again.', 'unsupported_format');
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            self::assertWorkbook($path, $maxUncompressed);
            $content = 'xlsx';
        } else {
            $utf16 = str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF");
            if (! $utf16 && str_contains($head, "\0")) {
                throw new SpreadsheetException('This file does not look like a CSV or Excel (.xlsx) file.', 'unsupported_format');
            }
            $content = 'csv';
        }
        $byExtension = match ($extension) {
            'csv', 'txt', 'tsv' => 'csv', 'xlsx' => 'xlsx', '' => null,
            default => throw new SpreadsheetException('Upload a .csv or .xlsx file.', 'unsupported_format'),
        };
        if ($byExtension !== null && $byExtension !== $content) {
            throw new SpreadsheetException('The file name ends in .'.$extension.' but its contents are '.($content === 'xlsx' ? 'an Excel workbook' : 'plain text').'. Re-save the file in the matching format and try again.', 'format_mismatch');
        }

        return $content;
    }

    private static function assertWorkbook(string $path, int $maxUncompressed): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new SpreadsheetException('This Excel file is damaged or not a real .xlsx workbook.', 'unsupported_format');
        }
        try {
            if ($zip->locateName('xl/workbook.xml') === false) {
                throw new SpreadsheetException('This archive is not an Excel (.xlsx) workbook.', 'unsupported_format');
            }
            $total = 0;
            if ($zip->numFiles > 5000) {
                throw new SpreadsheetException('This Excel file is too complex to import.', 'unsupported_format');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
                if ($total > $maxUncompressed) {
                    throw new SpreadsheetException('This Excel file expands to an unsafe size. Remove unused sheets and images, then try again.', 'too_large');
                }
            }
        } finally {
            $zip->close();
        }
    }

    // ---- header preparation ----------------------------------------------------------------

    private function prepare(): void
    {
        if ($this->format === 'csv') {
            $this->prepareCsv();
        } else {
            try {
                $names = (new XlsxReader)->listWorksheetNames($this->path);
            } catch (\Throwable $e) {
                report($e);
                throw new SpreadsheetException('This Excel file could not be read. Re-save it as .xlsx or .csv and try again.');
            }
            $this->sheetName = $names[0] ?? throw new SpreadsheetException('This workbook has no sheets.');
        }
        $header = null;
        foreach ($this->headerCandidates() as $number => $cells) {
            $header = [$number, $cells];
            break;
        }
        if (! $header) {
            throw new SpreadsheetException('The file has no header row. The first row must contain the column names.', 'no_header');
        }
        [$this->headerRow, $cells] = $header;
        $this->buildHeaders($cells);
    }

    private function buildHeaders(array $cells): void
    {
        $last = -1;
        foreach ($cells as $i => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $last = $i;
            }
        }
        if ($last + 1 > $this->options['max_columns']) {
            throw new SpreadsheetException('This file has more than '.$this->options['max_columns'].' columns.', 'too_many_columns');
        }
        $raw = [];
        $keys = [];
        $seenRaw = [];
        $seenKey = [];
        for ($i = 0; $i <= $last; $i++) {
            $text = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) ($cells[$i] ?? '')));
            $text = $text === '' ? 'Column '.($i + 1) : $text;
            $label = $text;
            for ($n = 2; isset($seenRaw[mb_strtolower($label)]); $n++) {
                $label = $text.' ('.$n.')';
            }
            $seenRaw[mb_strtolower($label)] = true;
            $key = self::normalizeHeader($label);
            $base = $key === '' ? 'column_'.($i + 1) : $key;
            $key = $base;
            for ($n = 2; isset($seenKey[$key]); $n++) {
                $key = $base.'_'.$n;
            }
            $seenKey[$key] = true;
            $raw[] = $label;
            $keys[] = $key;
        }
        $this->rawHeaders = $raw;
        $this->headers = $this->options['normalize_headers'] ? $keys : $raw;
    }

    /** First non-empty row only: [rowNumber => cells]. */
    private function headerCandidates(): \Generator
    {
        foreach ($this->rawRows() as $number => $cells) {
            if (! $this->blank($cells)) {
                yield $number => $cells;

                return;
            }
        }
    }

    // ---- row streams -----------------------------------------------------------------------

    /** Positional cells of every physical row (blank rows included), header row included. */
    private function rawRows(): \Generator
    {
        if ($this->format === 'csv') {
            $handle = $this->openCsv();
            $number = 0;
            try {
                while (($record = fgetcsv($handle, 0, $this->delimiter, '"', '')) !== false) {
                    $number++;
                    yield $number => array_map(fn ($v) => $this->clean($v), $record);
                }
            } finally {
                fclose($handle);
            }

            return;
        }
        yield from $this->xlsxRawRows();
    }

    private function csvRows(): \Generator
    {
        $data = 0;
        foreach ($this->rawRows() as $number => $cells) {
            if ($number <= $this->headerRow || $this->blank($cells)) {
                continue;
            }
            if (++$data > $this->options['max_rows']) {
                throw SpreadsheetException::tooManyRows($this->options['max_rows']);
            }
            yield $number => $this->assoc($cells);
        }
    }

    private function xlsxRows(): \Generator
    {
        $data = 0;
        foreach ($this->xlsxRawRows() as $number => $cells) {
            if ($number <= $this->headerRow || $this->blank($cells)) {
                continue;
            }
            if (++$data > $this->options['max_rows']) {
                throw SpreadsheetException::tooManyRows($this->options['max_rows']);
            }
            yield $number => $this->assoc($cells);
        }
    }

    private function assoc(array $cells): array
    {
        $row = [];
        foreach ($this->headers as $i => $key) {
            $row[$key] = $cells[$i] ?? null;
        }

        return $row;
    }

    private function blank(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = trim(str_replace("\xEF\xBB\xBF", '', $value));

        return $value === '' ? null : $value;
    }

    // ---- csv ---------------------------------------------------------------------------------

    private function prepareCsv(): void
    {
        $sample = (string) file_get_contents($this->path, false, null, 0, 65536);
        $filter = null;
        if (str_starts_with($sample, "\xFF\xFE")) {
            $filter = 'UTF-16LE';
        } elseif (str_starts_with($sample, "\xFE\xFF")) {
            $filter = 'UTF-16BE';
        } elseif (! $this->validUtf8($sample)) {
            $filter = 'WINDOWS-1252';
        }
        if ($filter) {
            $this->tempPath = tempnam(sys_get_temp_dir(), 'tab');
            $in = fopen($this->path, 'rb');
            $out = fopen($this->tempPath, 'wb');
            stream_filter_append($in, 'convert.iconv.'.$filter.'/UTF-8', STREAM_FILTER_READ);
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $this->csvPath = $this->tempPath;
        }
        $this->bomLength = str_starts_with((string) file_get_contents($this->csvPath, false, null, 0, 3), "\xEF\xBB\xBF") ? 3 : 0;
        $this->delimiter = $this->options['delimiter'] ?: $this->sniffDelimiter();
    }

    private function validUtf8(string $sample): bool
    {
        for ($trim = 0; $trim <= 3; $trim++) {
            if (mb_check_encoding($trim ? substr($sample, 0, -$trim) : $sample, 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    private function openCsv()
    {
        $handle = fopen($this->csvPath, 'rb');
        if ($this->bomLength) {
            fseek($handle, $this->bomLength);
        }

        return $handle;
    }

    /** Picks the delimiter that splits the first lines into the most consistent number of columns. */
    private function sniffDelimiter(): string
    {
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $handle = $this->openCsv();
            $counts = [];
            while (count($counts) < 10 && ($record = fgetcsv($handle, 0, $candidate, '"', '')) !== false) {
                if ($record === [null] || $record === []) {
                    continue;
                }
                $counts[] = count($record);
            }
            fclose($handle);
            if (! $counts || $counts[0] < 2) {
                continue;
            }
            $score = count(array_filter($counts, fn ($c) => $c === $counts[0])) * 1000 + $counts[0];
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    // ---- xlsx --------------------------------------------------------------------------------

    /**
     * Streams the first sheet with XMLReader (linear time, flat memory). Falls back to the chunked
     * PhpSpreadsheet reader only when the package layout is not understood (before any row is produced).
     */
    private function xlsxRawRows(): \Generator
    {
        try {
            $native = new XlsxStream($this->path);
        } catch (\Throwable $e) {
            yield from $this->xlsxRawRowsFallback();

            return;
        }
        foreach ($native->rows() as $number => $cells) {
            yield $number => array_map(fn ($v) => $this->clean($v), $cells);
        }
    }

    private function xlsxRawRowsFallback(): \Generator
    {
        $filter = new class implements IReadFilter
        {
            public int $start = 1;

            public int $end = 1;

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->start && $row <= $this->end;
            }
        };
        $reader = new XlsxReader;
        $reader->setReadEmptyCells(false);
        $reader->setReadDataOnly(false);
        $reader->setLoadSheetsOnly([$this->sheetName]);
        $reader->setReadFilter($filter);
        $chunk = max(100, (int) $this->options['xlsx_chunk_rows']);

        for ($start = 1; ; $start += $chunk) {
            $filter->start = $start;
            $filter->end = $start + $chunk - 1;
            try {
                $book = $reader->load($this->path);
                $sheet = $book->getActiveSheet();
                $highest = $sheet->getHighestDataRow();
                $rows = [];
                if ($highest >= $start) {
                    foreach ($sheet->getRowIterator($start, min($highest, $filter->end)) as $row) {
                        $cells = [];
                        $iterator = $row->getCellIterator();
                        $iterator->setIterateOnlyExistingCells(true);
                        foreach ($iterator as $cell) {
                            $cells[Coordinate::columnIndexFromString($cell->getColumn()) - 1] = $this->clean($this->cellValue($cell));
                        }
                        if ($cells) {
                            $max = max(array_keys($cells));
                            $filled = [];
                            for ($i = 0; $i <= $max; $i++) {
                                $filled[$i] = $cells[$i] ?? null;
                            }
                            $rows[$row->getRowIndex()] = $filled;
                        }
                    }
                }
                $book->disconnectWorksheets();
                unset($book, $sheet);
            } catch (SpreadsheetException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                throw new SpreadsheetException('This Excel file could not be read. Re-save it as .xlsx or .csv and try again.');
            }
            if ($highest < $start) {
                return;
            }
            yield from $rows;
        }
    }

    private function cellValue($cell): mixed
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            if (ExcelDate::isDateTime($cell)) {
                $date = ExcelDate::excelToDateTimeObject($value);

                return $date->format(fmod((float) $value, 1.0) === 0.0 ? 'Y-m-d' : 'Y-m-d H:i:s');
            }
            if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
                return (string) (int) $value;
            }

            return (string) $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
