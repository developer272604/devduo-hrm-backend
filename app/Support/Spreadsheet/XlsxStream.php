<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use XMLReader;
use ZipArchive;

/**
 * Minimal forward-only reader for the first worksheet of an .xlsx package (internal to TabularReader).
 * Linear time and flat memory: the sheet is streamed with XMLReader; only shared strings and the date
 * flags of cell styles are held in memory. Formulas are never evaluated (the cached value is returned).
 */
final class XlsxStream
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private string $sheetEntry;

    /** @var array<int,string> */
    private array $shared = [];

    /** @var array<int,bool> cellXfs index => is a date/time format */
    private array $dateStyles = [];

    private bool $date1904 = false;

    public function __construct(private string $path)
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open package.');
        }
        try {
            $this->sheetEntry = $this->locateSheet($zip);
            $this->loadStyles($zip);
            $this->loadSharedStrings($zip);
        } finally {
            $zip->close();
        }
    }

    /** @return \Generator<int,array<int,?string>> physical row number => cells by 0-based column index */
    public function rows(): \Generator
    {
        $xml = new XMLReader;
        if (! $xml->open('zip://'.str_replace('\\', '/', $this->path).'#'.$this->sheetEntry, null, LIBXML_NONET)) {
            throw new \RuntimeException('Cannot open worksheet.');
        }
        try {
            $number = 0;
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                    continue;
                }
                $number = (int) ($xml->getAttribute('r') ?: $number + 1);
                if ($xml->isEmptyElement) {
                    continue;
                }
                $cells = [];
                $column = -1;
                $depth = $xml->depth;
                while ($xml->read() && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
                    if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c') {
                        $ref = (string) $xml->getAttribute('r');
                        $column = $ref !== '' ? self::columnIndex($ref) : $column + 1;
                        $cells[$column] = $this->cell($xml);
                    }
                }
                if ($cells) {
                    $filled = [];
                    for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                        $filled[$i] = $cells[$i] ?? null;
                    }
                    yield $number => $filled;
                }
            }
        } finally {
            $xml->close();
        }
    }

    /** Reads the <c> element the reader is positioned on and leaves the reader on its end tag. */
    private function cell(XMLReader $xml): ?string
    {
        $type = (string) $xml->getAttribute('t');
        $style = (int) $xml->getAttribute('s');
        if ($xml->isEmptyElement) {
            return null;
        }
        $depth = $xml->depth;
        $value = null;
        $inline = null;
        while ($xml->read() && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($xml->localName === 'v') {
                $value = $xml->readString();
            } elseif ($xml->localName === 'is') {
                $inline = $this->richText($xml);
            }
        }
        if ($type === 'inlineStr') {
            return $inline;
        }
        if ($value === null) {
            return null;
        }

        return match ($type) {
            's' => $this->shared[(int) $value] ?? null,
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            'e' => null,
            'str', 'd' => $value,
            default => $this->number($value, $this->dateStyles[$style] ?? false),
        };
    }

    private function number(string $value, bool $isDate): ?string
    {
        if (! is_numeric($value)) {
            return $value;
        }
        $float = (float) $value;
        if ($isDate) {
            if ($float > 0 && $float < 1) {
                return gmdate('H:i:s', (int) round($float * 86400));
            }
            if ($this->date1904) {
                $date = (new \DateTimeImmutable('1904-01-01 00:00:00', new \DateTimeZone('UTC')))->modify('+'.(int) floor($float).' days');
                $seconds = (int) round(($float - floor($float)) * 86400);
                $date = $date->modify('+'.$seconds.' seconds');
            } else {
                $date = ExcelDate::excelToDateTimeObject($float, 'UTC');
            }

            return $date->format(fmod($float, 1.0) === 0.0 ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        if (floor($float) === $float && abs($float) < 1e15) {
            return (string) (int) $float;
        }

        return (string) $float;
    }

    /** Concatenated text of an <si>/<is> element, ignoring phonetic runs. Leaves the reader on its end tag. */
    private function richText(XMLReader $xml): string
    {
        if ($xml->isEmptyElement) {
            return '';
        }
        $depth = $xml->depth;
        $text = '';
        while ($xml->read() && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($xml->localName === 'rPh') {
                $xml->next();
                // next() lands on the following sibling; re-evaluate it in the loop condition by stepping back is not
                // possible, so handle it here when it is a text node holder.
                if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                    break;
                }
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 't') {
                    $text .= $xml->readString();
                }

                continue;
            }
            if ($xml->localName === 't') {
                $text .= $xml->readString();
            }
        }

        return $text;
    }

    private function locateSheet(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            throw new \RuntimeException('No workbook part.');
        }
        $book = simplexml_load_string($workbook, null, LIBXML_NONET);
        if (! $book) {
            throw new \RuntimeException('Unreadable workbook.');
        }
        $book->registerXPathNamespace('m', self::MAIN);
        $properties = $book->xpath('//m:workbookPr');
        $this->date1904 = $properties && in_array((string) ($properties[0]['date1904'] ?? ''), ['1', 'true'], true);
        $sheets = $book->xpath('//m:sheets/m:sheet');
        if (! $sheets) {
            throw new \RuntimeException('No sheets.');
        }
        $relationshipId = (string) $sheets[0]->attributes(self::REL)['id'];
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels !== false && ($relationships = simplexml_load_string($rels, null, LIBXML_NONET))) {
            foreach ($relationships->Relationship ?? $relationships->children() as $relationship) {
                if ((string) $relationship['Id'] === $relationshipId) {
                    $target = (string) $relationship['Target'];
                    $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                    if ($zip->locateName($entry) !== false) {
                        return $entry;
                    }
                }
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                return $name;
            }
        }
        throw new \RuntimeException('No worksheet part.');
    }

    private function loadStyles(ZipArchive $zip): void
    {
        $raw = $zip->getFromName('xl/styles.xml');
        if ($raw === false || ! ($styles = simplexml_load_string($raw, null, LIBXML_NONET))) {
            return;
        }
        $styles->registerXPathNamespace('m', self::MAIN);
        $custom = [];
        foreach ($styles->xpath('//m:numFmts/m:numFmt') ?: [] as $format) {
            $custom[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }
        foreach ($styles->xpath('//m:cellXfs/m:xf') ?: [] as $index => $xf) {
            $id = (int) $xf['numFmtId'];
            $this->dateStyles[$index] = isset($custom[$id])
                ? ExcelDate::isDateTimeFormatCode($custom[$id])
                : ($id >= 14 && $id <= 22 || $id >= 27 && $id <= 36 || $id >= 45 && $id <= 47 || $id >= 50 && $id <= 58);
        }
    }

    private function loadSharedStrings(ZipArchive $zip): void
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        $xml = new XMLReader;
        if (! $xml->open('zip://'.str_replace('\\', '/', $this->path).'#xl/sharedStrings.xml', null, LIBXML_NONET)) {
            return;
        }
        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si') {
                    $this->shared[] = $this->richText($xml);
                }
            }
        } finally {
            $xml->close();
        }
    }

    private static function columnIndex(string $reference): int
    {
        $index = 0;
        for ($i = 0, $length = strlen($reference); $i < $length; $i++) {
            $code = ord($reference[$i]);
            if ($code >= 97 && $code <= 122) {
                $code -= 32;
            }
            if ($code < 65 || $code > 90) {
                break;
            }
            $index = $index * 26 + ($code - 64);
        }

        return max(0, $index - 1);
    }
}
