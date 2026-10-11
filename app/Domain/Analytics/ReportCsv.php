<?php

namespace App\Domain\Analytics;

/** Renders an aggregate result as CSV with formula-cell escaping. Counts are masked for sensitive metrics. */
final class ReportCsv
{
    public static function sensitive(array $spec): bool
    {
        return str_starts_with($spec['metric'], 'payroll_') || ($spec['dimension'] ?? 'total') === 'gender';
    }

    /** @return array{csv:string,rows:int,masked:array<int,string>} */
    public static function render(array $result, bool $withCounts = false, bool $sensitive = false): array
    {
        $masked = (! $withCounts || $sensitive) ? ['numerator', 'denominator'] : [];
        $out = fopen('php://temp', 'w+');
        $header = ['Metric', 'Group', 'Value', 'Unit', 'Suppressed', 'From', 'To', 'Refreshed at'];
        if (! $masked) {
            array_push($header, 'Numerator', 'Denominator');
        }
        fputcsv($out, $header, ',', '"', '');
        foreach ($result['rows'] as $row) {
            $value = is_array($row['value'] ?? null)
                ? 'gross='.$row['value']['gross'].'; net='.$row['value']['net'].'; gross_change_percent='.($row['value']['gross_change_percent'] ?? '').'; net_change_percent='.($row['value']['net_change_percent'] ?? '')
                : ($row['value'] ?? '');
            $line = [$result['label'], self::safe($row['group']), self::safe($value), $result['unit'], $row['suppressed'] ? 'yes' : 'no', $result['period']['from'], $result['period']['to'], $result['refreshed_at']];
            if (! $masked) {
                array_push($line, $row['numerator'] ?? '', $row['denominator'] ?? '');
            }
            fputcsv($out, $line, ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return ['csv' => $csv, 'rows' => count($result['rows']), 'masked' => $masked];
    }

    private static function safe(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[=+\-@\t\r\n]/u', $v) ? "'".$v : $v;
    }
}
