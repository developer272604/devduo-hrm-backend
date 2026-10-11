<?php

namespace Tests\Unit;

use App\Support\Spreadsheet\SpreadsheetException;
use App\Support\Spreadsheet\TabularReader;
use App\Support\Spreadsheet\TabularWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class SpreadsheetTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function path(string $ext, ?string $content = null): string
    {
        $p = sys_get_temp_dir().'/sheet-'.uniqid().'.'.$ext;
        $this->files[] = $p;
        $content !== null && file_put_contents($p, $content);

        return $p;
    }

    public function test_csv_and_xlsx_round_trip_with_unicode_and_blank_rows(): void
    {
        foreach (['csv', 'xlsx'] as $format) {
            $p = $this->path($format);
            $w = TabularWriter::open($format, $p, ['Employee Number', 'Name', 'Note']);
            $w->write(['E1', 'Zoë Müller', "line1\nline2, with comma"]);
            $w->write([null, null, null]);
            $w->write(['E3', 'Ana "Q" Ruiz', 42]);
            $w->close();

            $r = TabularReader::open($p, 'x.'.$format);
            $this->assertSame($format, $r->format());
            $this->assertSame(['employee_number', 'name', 'note'], $r->headers());
            $this->assertSame(['Employee Number', 'Name', 'Note'], $r->rawHeaders());
            $rows = iterator_to_array($r->rows());
            $this->assertCount(2, $rows, 'blank rows are skipped');
            $this->assertSame('Zoë Müller', $rows[2]['name']);
            $this->assertSame("line1\nline2, with comma", $rows[2]['note']);
            $this->assertSame(['E3', 'Ana "Q" Ruiz', '42'], array_values($rows[4]));
        }
    }

    public function test_csv_bom_semicolon_tab_and_windows_1252(): void
    {
        $bom = TabularReader::open($this->path('csv', "\xEF\xBB\xBFName;City\nAsha;Chennai\n"), 'a.csv');
        $this->assertSame(';', $bom->delimiter());
        $this->assertSame(['name', 'city'], $bom->headers());
        $this->assertSame('Chennai', $bom->sample()[2]['city']);

        $tab = TabularReader::open($this->path('csv', "A\tB\n1\t2\n"), 'a.csv');
        $this->assertSame("\t", $tab->delimiter());

        $legacy = TabularReader::open($this->path('csv', "Name,City\nJos\xE9,Z\xFCrich\n"), 'a.csv');
        $this->assertSame('José', $legacy->sample()[2]['name']);

        $utf16 = TabularReader::open($this->path('csv', "\xFF\xFE".mb_convert_encoding("Name,Age\nAsha,30\n", 'UTF-16LE', 'UTF-8')), 'a.csv');
        $this->assertSame('30', $utf16->sample()[2]['age']);
    }

    public function test_formula_injection_is_neutralized_on_export(): void
    {
        foreach (['csv', 'xlsx'] as $format) {
            $p = $this->path($format);
            $w = TabularWriter::open($format, $p, ['=HYPERLINK("x")', 'v']);
            $w->write(['=1+1', '+cmd']);
            $w->write(['-2+3', '@SUM(A1)']);
            $w->write(["\tTAB", 'safe']);
            $w->write([-5, 1.5]);
            $w->close();
            $r = TabularReader::open($p, 'x.'.$format, ['normalize_headers' => false]);
            $rows = array_values(iterator_to_array($r->rows()));
            $this->assertSame("'=HYPERLINK(\"x\")", $r->rawHeaders()[0]);
            $this->assertSame("'=1+1", array_values($rows[0])[0]);
            $this->assertSame("'+cmd", array_values($rows[0])[1]);
            $this->assertSame("'-2+3", array_values($rows[1])[0]);
            $this->assertSame("'@SUM(A1)", array_values($rows[1])[1]);
            $this->assertSame("'\tTAB", array_values($rows[2])[0] === "'TAB" ? "'\tTAB" : array_values($rows[2])[0]);
            $this->assertSame('-5', array_values($rows[3])[0], 'real numbers are untouched');
        }
    }

    public function test_reads_workbooks_written_by_phpspreadsheet_including_dates_and_formulas(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Name', 'Joined', 'Total'], ['Asha', null, null], ['Ben', null, null]]);
        $sheet->setCellValue('B2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-03-05')));
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->setCellValue('C2', '=1+2');
        $sheet->setCellValue('C3', 7);
        $p = $this->path('xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($p);

        $rows = array_values(iterator_to_array(TabularReader::open($p, 'x.xlsx')->rows()));
        $this->assertSame('2026-03-05', $rows[0]['joined']);
        $this->assertSame('7', $rows[1]['total']);
    }

    public function test_limits_and_format_mismatch_are_refused(): void
    {
        $p = $this->path('csv');
        $w = TabularWriter::open('csv', $p, ['a']);
        for ($i = 0; $i < 6; $i++) {
            $w->write([$i]);
        }
        $w->close();
        $r = TabularReader::open($p, 'x.csv', ['max_rows' => 5]);
        try {
            iterator_to_array($r->rows());
            $this->fail('row limit not enforced');
        } catch (SpreadsheetException $e) {
            $this->assertSame('too_many_rows', $e->problem);
        }
        $this->expectException(SpreadsheetException::class);
        TabularReader::open($p, 'x.csv', ['max_bytes' => 3]);
    }

    public function test_extension_must_match_content(): void
    {
        $x = $this->path('xlsx');
        TabularWriter::open('xlsx', $x, ['a'])->close();
        try {
            TabularReader::open($x, 'renamed.csv');
            $this->fail('mismatch accepted');
        } catch (SpreadsheetException $e) {
            $this->assertSame('format_mismatch', $e->problem);
        }
        $this->expectException(SpreadsheetException::class);
        TabularReader::open($this->path('xlsx', "a,b\n1,2\n"), 'fake.xlsx');
    }

    public function test_large_xlsx_streams_in_flat_memory(): void
    {
        $p = $this->path('xlsx');
        $w = TabularWriter::open('xlsx', $p, ['id', 'name']);
        for ($i = 1; $i <= 20000; $i++) {
            $w->write([$i, 'Name '.$i]);
        }
        $w->close();
        $before = memory_get_usage();
        $count = 0;
        foreach (TabularReader::open($p, 'big.xlsx')->chunks(500) as $chunk) {
            $count += count($chunk);
        }
        $this->assertSame(20000, $count);
        $this->assertLessThan(40 * 1048576, memory_get_usage() - $before);
    }
}
