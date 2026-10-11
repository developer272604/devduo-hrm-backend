<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\Models\Report;
use App\Domain\Audit\Audit;
use Illuminate\Http\Request;

final class AnalyticsController
{
    public function __construct(private AnalyticsService $service) {}

    public function catalog(Request $r)
    {
        return ['data' => $this->service->catalog($r->user())];
    }

    public function query(Request $r)
    {
        return $this->service->query($r->user(), $r->all());
    }

    public function reports(Request $r)
    {
        return Report::where('owner_id', $r->user()->id)->latest('id')->paginate(25);
    }

    public function save(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'spec' => 'required|array:metric,from,to,dimension']);
        $spec = $this->service->validateSpec($d['spec']);
        $this->service->query($r->user(), $spec, false); // Recheck source permissions before saving.
        $report = Report::create(['name' => $d['name'], 'spec' => $spec, 'owner_id' => $r->user()->id]);
        Audit::record('analytics.report.saved', 'analytics', 'report:'.$report->uuid);

        return response()->json($report, 201);
    }

    public function run(Request $r, string $uuid)
    {
        $report = Report::where('owner_id', $r->user()->id)->where('uuid', $uuid)->firstOrFail();

        return $this->service->query($r->user(), $report->spec);
    }

    public function delete(Request $r, string $uuid)
    {
        $report = Report::where('owner_id', $r->user()->id)->where('uuid', $uuid)->firstOrFail();
        $report->delete();
        Audit::record('analytics.report.deleted', 'analytics', 'report:'.$uuid);

        return response()->noContent();
    }

    public function export(Request $r)
    {
        $result = $this->service->query($r->user(), $r->all(), true, 'export');

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Group', 'Value', 'Unit', 'Suppressed', 'From', 'To', 'Refreshed at'], ',', '"', '');
            foreach ($result['rows'] as $row) {
                $label = $row['group'];
                if (preg_match('/^[=+\-@\t\r\n]/u', $label)) {
                    $label = "'".$label;
                }
                $value = is_array($row['value'] ?? null)
                    ? 'gross='.$row['value']['gross'].'; net='.$row['value']['net'].'; gross_change_percent='.$row['value']['gross_change_percent'].'; net_change_percent='.$row['value']['net_change_percent']
                    : ($row['value'] ?? '');
                if (is_string($value) && preg_match('/^[=+\-@\t\r\n]/u', $value)) $value = "'".$value;
                fputcsv($out, [$result['label'], $label, $value, $result['unit'], $row['suppressed'] ? 'yes' : 'no', $result['period']['from'], $result['period']['to'], $result['refreshed_at']], ',', '"', '');
            } fclose($out);
        }, 'workforce-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function scenario(Request $r)
    {
        return $this->service->scenario($r->user(), $r->all());
    }
}
