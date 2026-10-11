<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Operations\Bulk\LeaveBulkActions;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Reports\ReportCatalogue;
use App\Domain\People\Boot\OperationsBoot as PeopleOps;
use App\Http\Controllers\Controller;
use App\Jobs\Leave\GenerateLeaveExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Reports, queued secure exports and the bulk-actions registry. */
class ReportController extends Controller
{
    public function catalogue(ReportCatalogue $c)
    {
        return ['reports' => $c->catalogue(), 'filters' => ReportCatalogue::FILTERS];
    }

    public function report(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');

        return $c->run($r->user(), $report, $this->filters($r), $r->input('sort'), $r->input('dir') === 'asc' ? 'asc' : 'desc', max(1, (int) $r->input('page', 1)), LeaveOps::perPage($r->input('per_page')));
    }

    public function export(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');
        abort_unless(array_filter(ReportCatalogue::PERMISSIONS, fn ($p) => $r->user()->allows($p)), 403, 'You do not have access to leave reports.');
        $r->validate(['format' => 'required|in:csv,xlsx']);
        $f = $this->filters($r);
        $uuid = (string) Str::uuid();
        $id = DB::table('leave_exports')->insertGetId(['uuid' => $uuid, 'tenant_id' => LeaveOps::tenantId(), 'user_id' => $r->user()->id, 'report' => $report, 'format' => $r->input('format'), 'filters' => json_encode($f), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('exported', 'leave', 'report:'.$report, ['export' => $uuid, 'format' => $r->input('format'), 'filters' => $f]);
        GenerateLeaveExport::dispatch(LeaveOps::tenantId(), $id);

        return response()->json($this->exportRow(DB::table('leave_exports')->where('id', $id)->first()), 202);
    }

    public function exports(Request $r)
    {
        $rows = DB::table('leave_exports')->where('tenant_id', LeaveOps::tenantId())->where('user_id', $r->user()->id)->orderByDesc('id')->limit(50)->get();

        return ['data' => $rows->map(fn ($x) => $this->exportRow($x))->all()];
    }

    public function download(Request $r, string $uuid)
    {
        $x = DB::table('leave_exports')->where('tenant_id', LeaveOps::tenantId())->where('user_id', $r->user()->id)->where('uuid', $uuid)->first();
        abort_unless($x, 404);
        abort_unless($x->status === 'completed' && $x->path && $x->expires_at > now(), 410, 'This export is not available any more. Please create a new one.');
        Audit::record('downloaded', 'leave', 'export:'.$uuid, ['report' => $x->report]);

        return Storage::disk(PeopleOps::EXPORTS)->download($x->path, 'leave-'.$x->report.'-'.substr($uuid, 0, 8).'.'.$x->format);
    }

    // ---- bulk actions ------------------------------------------------------------------------------------
    public function bulkActions(Request $r, LeaveBulkActions $registry)
    {
        return ['data' => $registry->catalogue($r->user())];
    }

    public function bulkPreview(Request $r, LeaveBulkActions $registry, string $key)
    {
        [$action, $employees, $params] = $this->bulk($r, $registry, $key);
        $result = $action->preview($r->user(), $employees, $params);

        return ['action' => $key, 'count' => count($employees), 'results' => $this->rows($employees, $result)];
    }

    public function bulkRun(Request $r, LeaveBulkActions $registry, string $key)
    {
        [$action, $employees, $params] = $this->bulk($r, $registry, $key);
        $results = [];
        foreach ($employees as $e) {
            try {
                $results[$e->id] = $action->run($r->user(), $e, $params);
            } catch (\Throwable $ex) {
                $results[$e->id] = ['status' => 'error', 'reason' => $ex instanceof ValidationException ? collect($ex->errors())->flatten()->first() : 'Could not complete this employee.'];
            }
        }
        Audit::record('bulk_run', 'leave', 'bulk_action:'.$key, ['count' => count($employees), 'params' => $params, 'ok' => count(array_filter($results, fn ($x) => $x['status'] === 'ok'))]);

        return response()->json(['action' => $key, 'count' => count($employees), 'results' => $this->rows($employees, $results)], 200);
    }

    /** @return array{0:\App\Domain\Leave\Operations\Bulk\LeaveBulkAction,1:array<int,object>,2:array<string,mixed>} */
    private function bulk(Request $r, LeaveBulkActions $registry, string $key): array
    {
        $action = $registry->find($key);
        abort_unless($action && $r->user()->allows($action->permission()), 404, 'That bulk action does not exist.');
        $d = $r->validate(['employees' => 'required|array|min:1|max:'.LeaveBulkActions::MAX, 'employees.*' => 'string', 'params' => 'nullable|array']);
        $params = $action->validate($d['params'] ?? []);
        $employees = LeaveOps::employees($r->user(), $action->permission())->whereIn('e.uuid', $d['employees'])->get(['e.id', 'e.uuid', 'e.employee_number', 'p.display_name'])->all();
        abort_if($employees === [], 404, 'None of the selected employees are in your scope.');

        return [$action, $employees, $params];
    }

    /** @param array<int,object> $employees */
    private function rows(array $employees, array $results): array
    {
        return array_map(fn ($e) => ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name] + ($results[$e->id] ?? ['status' => 'skip']), $employees);
    }

    private function exportRow(object $x): array
    {
        return ['uuid' => $x->uuid, 'report' => $x->report, 'format' => $x->format, 'status' => $x->status, 'row_count' => (int) $x->row_count, 'expires_at' => LeaveOps::iso($x->expires_at), 'error' => $x->error,
            'created_at' => LeaveOps::iso($x->created_at), 'download' => $x->status === 'completed' ? '/api/v1/leave/exports/'.$x->uuid.'/download' : null];
    }

    /** Validated report filters (whitelist). */
    private function filters(Request $r): array
    {
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'department_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'legal_entity_id' => 'nullable|integer']);

        return array_filter($r->only(ReportCatalogue::FILTERS), fn ($v) => $v !== null && $v !== '' && ! is_array($v));
    }
}
