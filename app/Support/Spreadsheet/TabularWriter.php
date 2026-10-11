<?php

namespace App\Support\Spreadsheet;

/**
 * Writes CSV and XLSX files row by row (constant memory: XLSX rows are streamed to a temporary file and
 * zipped on close, so a 100k-row export does not build a PhpSpreadsheet object graph).
 *
 *   $w = TabularWriter::open('xlsx', $path, ['Employee number', 'Name']);
 *   $w->write(['EMP-1', 'Asha Rao']);
 *   $w->close();
 *
 * Formula-injection safe: any string cell whose first non-space character is = + - @ (or that starts with a
 * tab / carriage return) is prefixed with a single quote so spreadsheet software shows it as text. Real PHP
 * ints and floats are written as numbers and never altered. Disable per file with ['sanitize' => false]
 * only for trusted, machine-read output.
 *
 * Options: sanitize (true), bom (true, CSV only: lets Excel detect UTF-8), sheet_title ('Sheet1'),
 *          min_width (8) / max_width (60) for XLSX column widths.
 */
final class TabularWriter
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private $handle;

    private int $rows = 0;

    private bool $closed = false;

    /** @var array<int,int> */
    private array $widths = [];

    private ?string $body = null;

    private function __construct(private string $format, private string $path, private array $headers, private array $options)
    {
    }

    public static function open(string $format, string $path, array $headers, array $options = []): self
    {
        $format = strtolower($format);
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Format must be csv or xlsx.');
        }
        if ($headers === []) {
            throw new \InvalidArgumentException('At least one column is required.');
        }
        $writer = new self($format, $path, array_values($headers), $options + ['sanitize' => true, 'bom' => true, 'sheet_title' => 'Sheet1', 'min_width' => 8, 'max_width' => 60]);
        $writer->start();

        return $writer;
    }

    /** Neutralizes spreadsheet formulas in a string cell. */
    public static function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $first = ltrim($value)[0] ?? '';
        if (in_array($first, ['=', '+', '-', '@'], true) || in_array($value[0], ["\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** Appends one data row (positional values). */
    public function write(array $values): void
    {
        $values = array_values($values);
        if ($this->options['sanitize']) {
            $values = array_map([self::class, 'sanitize'], $values);
        }
        $this->rows++;
        if ($this->format === 'csv') {
            fputcsv($this->handle, array_map(fn ($v) => $this->csvScalar($v), $values), ',', '"', '', "\n");

            return;
        }
        $this->xlsxRow($values, $this->rows + 1, false);
    }

    /** @param iterable<array> $rows */
    public function writeMany(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->write($row);
        }
    }

    /** Number of data rows written (header excluded). */
    public function count(): int
    {
        return $this->rows;
    }

    public function close(): string
    {
        if ($this->closed) {
            return $this->path;
        }
        $this->closed = true;
        if ($this->format === 'csv') {
            fclose($this->handle);

            return $this->path;
        }
        fclose($this->handle);
        $this->finishXlsx();

        return $this->path;
    }

    public function __destruct()
    {
        if (! $this->closed) {
            try {
                $this->close();
            } catch (\Throwable) {
            }
        }
        if ($this->body && is_file($this->body)) {
            @unlink($this->body);
        }
    }

    // ---- csv ---------------------------------------------------------------------------------

    private function start(): void
    {
        if ($this->format === 'csv') {
            $this->handle = fopen($this->path, 'wb');
            if ($this->options['bom']) {
                fwrite($this->handle, "\xEF\xBB\xBF");
            }
            $headers = $this->options['sanitize'] ? array_map([self::class, 'sanitize'], $this->headers) : $this->headers;
            fputcsv($this->handle, array_map(fn ($v) => $this->csvScalar($v), $headers), ',', '"', '', "\n");

            return;
        }
        $this->body = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->handle = fopen($this->body, 'wb');
        $this->xlsxRow($this->options['sanitize'] ? array_map([self::class, 'sanitize'], $this->headers) : $this->headers, 1, true);
    }

    private function csvScalar(mixed $value): string
    {
        return match (true) {
            $value === null => '', is_bool($value) => $value ? 'TRUE' : 'FALSE', $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => (string) $value, default => (string) json_encode($value),
        };
    }

    // ---- xlsx --------------------------------------------------------------------------------

    private function xlsxRow(array $values, int $rowNumber, bool $header): void
    {
        $xml = '<row r="'.$rowNumber.'">';
        foreach ($values as $i => $value) {
            if ($i >= count($this->headers) && $value === null) {
                continue;
            }
            $ref = self::column($i).$rowNumber;
            $style = $header ? ' s="1"' : '';
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if (is_bool($value)) {
                $xml .= '<c r="'.$ref.'"'.$style.' t="b"><v>'.($value ? 1 : 0).'</v></c>';
                $this->track($i, $value ? 4 : 5);
            } elseif ((is_int($value) && abs($value) <= 9007199254740991) || (is_float($value) && is_finite($value))) {
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
                $this->track($i, strlen((string) $value));
            } else {
                $text = is_scalar($value) ? (string) $value : (string) json_encode($value);
                $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
                $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                $this->track($i, mb_strlen(mb_substr($text, 0, 120)));
            }
        }
        fwrite($this->handle, $xml.'</row>');
    }

    private function track(int $column, int $length): void
    {
        if ($length > ($this->widths[$column] ?? 0)) {
            $this->widths[$column] = $length;
        }
    }

    private static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    private function finishXlsx(): void
    {
        $columns = count($this->headers);
        $last = self::column($columns - 1);
        $lastRow = $this->rows + 1;
        $cols = '';
        for ($i = 0; $i < $columns; $i++) {
            $width = min($this->options['max_width'], max($this->options['min_width'], ($this->widths[$i] ?? 0) + 2));
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $sheet = tempnam(sys_get_temp_dir(), 'xlsh');
        $out = fopen($sheet, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="'.self::NS.'"><dimension ref="A1:'.$last.$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/><cols>'.$cols.'</cols><sheetData>');
        $in = fopen($this->body, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fwrite($out, '</sheetData><autoFilter ref="A1:'.$last.$lastRow.'"/></worksheet>');
        fclose($out);

        $title = preg_replace('/[\[\]:*?\/\\\\]/', ' ', (string) $this->options['sheet_title']);
        $title = htmlspecialchars(mb_substr(trim($title) ?: 'Sheet1', 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip = new \ZipArchive;
        if ($zip->open($this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($sheet);
            throw new \RuntimeException('The spreadsheet file could not be created.');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $xml.'<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$title.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $xml.'<styleSheet xmlns="'.self::NS.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $closed = $zip->close();
        @unlink($sheet);
        if (! $closed) {
            throw new \RuntimeException('The spreadsheet file could not be written.');
        }
    }
}
