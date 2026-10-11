<?php
namespace App\Http\Controllers\Operations;

use App\Domain\Operations\OperationsReportService;
use Illuminate\Http\Request;

final class ReportController {
    public function __construct(private OperationsReportService $reports) {}
    private function params(Request $r): array {
        return $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'as_of' => 'nullable|date_format:Y-m-d', 'group' => 'nullable|in:category,department',
            'status' => 'nullable|string|max:60', 'severity' => 'nullable|in:warn,block,escalate']);
    }
    public function show(Request $r, string $report) { return $this->reports->build($r->user(), $report, $this->params($r)); }
    public function export(Request $r, string $report) {
        $d = $r->validate(['purpose' => 'required|string|min:10|max:300', 'format' => 'nullable|in:csv,xlsx']);
        [$path, $name] = $this->reports->export($r->user(), $report, $this->params($r), $d['format'] ?? 'csv', $d['purpose']);
        return response()->download($path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }
}
