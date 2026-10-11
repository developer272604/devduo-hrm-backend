<?php

namespace App\Http\Controllers\People\Insights;

use App\Domain\Audit\Audit;
use App\Domain\Quality\DataQualityFinding;
use App\Domain\Quality\FindingService;
use App\Domain\Quality\ScanDataQuality;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualityController extends PeopleController
{
    public function index(Request $r, FindingService $service)
    {
        $f = $r->validate(['severity' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'status' => 'nullable|string|max:100', 'rule' => 'nullable|string|max:100',
            'employee' => 'nullable|string|max:40', 'per_page' => 'nullable|integer']);
        $q = $service->visible($r->user());
        foreach (['severity', 'category', 'status', 'rule'] as $key) {
            if (! empty($f[$key])) {
                $q->whereIn($key, array_map('trim', explode(',', $f[$key])));
            }
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', DB::table('employees')->where('tenant_id', $r->user()->tenant_id)->where('uuid', $f['employee'])->value('id') ?? 0);
        }
        $page = $q->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'action_required' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")->orderByDesc('id')->paginate($this->perPage($r));
        $page->setCollection(collect($service->present($page->getCollection())));

        return $page;
    }

    public function summary(Request $r, FindingService $service)
    {
        $active = fn () => $service->visible($r->user())->whereIn('status', DataQualityFinding::ACTIVE);
        $by = fn (string $column) => $active()->selectRaw("$column as k, COUNT(*) as n")->groupBy($column)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();

        return ['open' => $active()->count(), 'by_severity' => $by('severity'), 'by_category' => $by('category'), 'by_rule' => $by('rule'),
            'by_status' => $service->visible($r->user())->selectRaw('status as k, COUNT(*) as n')->groupBy('status')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'last_scan_at' => $service->visible($r->user())->max('last_seen_at')];
    }

    public function scan(Request $r)
    {
        ScanDataQuality::dispatch($r->user()->tenant_id);
        Audit::record('quality_scan_requested', 'people', 'quality');

        return response()->json(['queued' => true], 202);
    }

    public function act(Request $r, FindingService $service, int $id, string $action)
    {
        $finding = $service->find($r->user(), $id);
        $user = $r->user();
        $finding = match ($action) {
            'acknowledge' => $service->acknowledge($finding, $user),
            'reopen' => $service->reopen($finding, $user),
            'resolve' => $service->resolve($finding, $user, $r->validate(['resolution' => 'nullable|string|max:1000'])['resolution'] ?? null),
            'dismiss' => $service->dismiss($finding, $user, $r->validate(['resolution' => 'nullable|string|max:1000', 'reason' => 'nullable|string|max:1000'])['resolution'] ?? $r->input('reason')),
            'assign' => $service->assign($finding, $user, (int) $r->validate(['assignee_id' => 'required|integer'])['assignee_id']),
            default => abort(404),
        };

        return $service->present([$finding->refresh()])[0];
    }
}
