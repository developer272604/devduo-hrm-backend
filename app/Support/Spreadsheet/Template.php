<?php

namespace App\Support\Spreadsheet;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloadable import template: a header row (+ example rows) in CSV or XLSX.
 *
 *   return Template::download([['key' => 'first_name'], 'last_name'], [['Asha', 'Rao']], 'xlsx', 'employee-import');
 *
 * Columns are strings or ['key' => header text, ...]; example rows are positional.
 */
final class Template
{
    /** @return array{0:string,1:string} [path, filename] */
    public static function build(array $columns, array $exampleRows = [], string $format = 'csv', string $basename = 'template'): array
    {
        $format = strtolower($format) === 'xlsx' ? 'xlsx' : 'csv';
        $headers = array_map(fn ($c) => is_array($c) ? (string) ($c['key'] ?? $c['label'] ?? '') : (string) $c, $columns);
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        $writer = TabularWriter::open($format, $path, $headers, ['sheet_title' => 'Template']);
        foreach ($exampleRows as $row) {
            $writer->write(array_pad(array_values($row), count($headers), null));
        }
        $writer->close();

        return [$path, preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename).'.'.$format];
    }

    public static function download(array $columns, array $exampleRows = [], string $format = 'csv', string $basename = 'template'): BinaryFileResponse
    {
        [$path, $filename] = self::build($columns, $exampleRows, $format, $basename);

        return response()->download($path, $filename, [
            'Content-Type' => str_ends_with($filename, '.xlsx') ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
