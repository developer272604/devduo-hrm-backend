<?php

namespace App\Http\Controllers\Attendance\Operations;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Operations\Coverage;
use App\Domain\Attendance\Operations\MyAttendance;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Attendance\Operations\OperationsHealth;
use App\Domain\Attendance\Operations\PayrollReadiness;
use App\Domain\Attendance\Operations\ReportCatalogue;
use App\Domain\Attendance\Operations\SettingsService;
use App\Domain\Attendance\Operations\TeamAttendance;
use App\Domain\Audit\Audit;
use App\Domain\People\Boot\OperationsBoot as PeopleOps;
use App\Domain\People\Models\SavedView;
use App\Http\Controllers\Controller;
use App\Jobs\Attendance\GenerateAttendanceExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    // ---- settings -------------------------------------------------------------------------------
    public function settings(Request $r, SettingsService $s, ?string $section = null)
    {
        return $section ? $s->get($section) : ['sections' => $s->all()];
    }

    public function updateSettings(Request $r, SettingsService $s, string $section)
    {
        return $s->update($section, $r->json()->all() ?: $r->all());
    }

    // ---- read models ---------------------------------------------------------------------------
    public function me(Request $r, MyAttendance $m)
    {
        return $m->summary($r->user());
    }

    public function teamSummary(Request $r, TeamAttendance $t)
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        abort_if(strtotime($d['to']) - strtotime($d['from']) > 366 * 86400, 422, 'Choose a range of at most one year.');

        return $t->summary($r->user(), $d['from'], $d['to'], (int) ($d['page'] ?? 1), Ops::perPage($d['per_page'] ?? null));
    }

    public function dashboard(Request $r, TeamAttendance $t)
    {
        $user = $r->user();
        $today = Ops::today();
        $out = ['date' => $today, 'me' => null, 'team' => null];
        if ($user->allows('attendance.view')) {
            $me = app(MyAttendance::class)->summary($user);
            $out['me'] = isset($me['today']) ? ['status' => $me['today']['status'], 'status_label' => $me['today']['status_label'], 'checked_in' => $me['today']['checked_in'], 'worked_minutes_so_far' => $me['today']['worked_minutes_so_far'], 'open_exceptions' => $me['open_exceptions']['count']] : null;
        }
        if ($user->allows('attendance.team.view')) {
            $team = $t->today($user, $today, 5);
            $ids = Ops::employeeIds($user, 'attendance.team.view');
            $away = $ids ? app(LeaveDataProvider::class)->coverageForRange($ids, $today, $today) : [];
            $out['team'] = ['size' => $team['team_size'], 'in' => $team['counts']['in'], 'out' => max(0, $team['team_size'] - $team['counts']['in']), 'absent' => $team['counts']['absent'], 'late' => $team['counts']['late'],
                'missing_punches' => $team['counts']['missing_punches'], 'pending_regularizations' => $team['groups']['pending_regularizations']['count'], 'people_away' => ['count' => count($away), 'source' => 'leave_provider']];
        }

        return $out;
    }

    public function health(OperationsHealth $h)
    {
        return $h->report();
    }

    // ---- payroll -------------------------------------------------------------------------------
    public function readiness(Request $r, PayrollReadiness $p)
    {
        $d = $r->validate(['payroll_group' => 'nullable|string|max:60', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(strtotime($d['to']) - strtotime($d['from']) > 366 * 86400, 422, 'Choose a range of at most one year.');

        return $p->readiness($r->user(), $d['payroll_group'] ?? null, $d['from'], $d['to']);
    }

    public function output(Request $r, PayrollReadiness $p)
    {
        $d = $r->validate(['payroll_group' => 'nullable|string|max:60', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'include_open' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        abort_if(strtotime($d['to']) - strtotime($d['from']) > 366 * 86400, 422, 'Choose a range of at most one year.');

        return $p->output($r->user(), $d['payroll_group'] ?? null, $d['from'], $d['to'], $r->boolean('include_open'), (int) ($d['page'] ?? 1), Ops::perPage($d['per_page'] ?? null));
    }

    // ---- reports -------------------------------------------------------------------------------
    public function catalogue(ReportCatalogue $c)
    {
        return ['reports' => $c->catalogue(), 'filters' => ReportCatalogue::FILTERS];
    }

    public function report(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');
        $f = $this->filters($r);

        return $c->run($r->user(), $report, $f, $r->input('sort'), $r->input('dir') === 'asc' ? 'asc' : 'desc', max(1, (int) $r->input('page', 1)), Ops::perPage($r->input('per_page')));
    }

    public function export(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');
        $r->validate(['format' => 'required|in:csv,xlsx']);
        $f = $this->filters($r);
        $uuid = (string) Str::uuid();
        $id = DB::table('attendance_exports')->insertGetId(['uuid' => $uuid, 'tenant_id' => Ops::tenantId(), 'user_id' => $r->user()->id, 'report' => $report, 'format' => $r->input('format'),
            'filters' => json_encode($f), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('exported', 'attendance', 'report:'.$report, ['export' => $uuid, 'format' => $r->input('format'), 'filters' => $f]);
        GenerateAttendanceExport::dispatch(Ops::tenantId(), $id);

        return response()->json($this->exportRow(DB::table('attendance_exports')->where('id', $id)->first()), 202);
    }

    public function exports(Request $r)
    {
        $rows = DB::table('attendance_exports')->where('tenant_id', Ops::tenantId())->where('user_id', $r->user()->id)->orderByDesc('id')->limit(50)->get();

        return ['data' => $rows->map(fn ($x) => $this->exportRow($x))->all()];
    }

    public function download(Request $r, string $uuid)
    {
        $x = DB::table('attendance_exports')->where('tenant_id', Ops::tenantId())->where('user_id', $r->user()->id)->where('uuid', $uuid)->first();
        abort_unless($x, 404);
        abort_unless($x->status === 'completed' && $x->path && $x->expires_at > now(), 410, 'This export is not available any more. Please create a new one.');
        Audit::record('downloaded', 'attendance', 'export:'.$uuid, ['report' => $x->report]);

        return Storage::disk(PeopleOps::EXPORTS)->download($x->path, 'attendance-'.$x->report.'-'.substr($uuid, 0, 8).'.'.$x->format);
    }

    private function exportRow(object $x): array
    {
        return ['uuid' => $x->uuid, 'report' => $x->report, 'format' => $x->format, 'status' => $x->status, 'row_count' => (int) $x->row_count, 'expires_at' => Ops::iso($x->expires_at), 'error' => $x->error,
            'created_at' => Ops::iso($x->created_at), 'download' => $x->status === 'completed' ? '/api/v1/attendance/exports/'.$x->uuid.'/download' : null];
    }

    /** Validated report filters (whitelist). */
    private function filters(Request $r): array
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'employee' => 'nullable|uuid', 'employee_number' => 'nullable|string|max:60', 'department_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'legal_entity_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|string|max:30', 'exception_type' => 'nullable|string|max:40', 'manager' => 'nullable|uuid',
            'source' => 'nullable|string|max:20', 'policy_id' => 'nullable|integer', 'device_id' => 'nullable|integer']);

        return array_filter($d, fn ($v) => $v !== null && $v !== '');
    }

    // ---- saved views -----------------------------------------------------------------------------
    public function views(Request $r)
    {
        $rows = SavedView::query()->where('module', 'attendance')->where(fn ($w) => $w->where('user_id', $r->user()->id)->orWhere('shared', true))->orderBy('name')->get();

        return ['data' => $rows->map(fn ($v) => $this->view($v, $r))->all()];
    }

    public function saveView(Request $r, ?string $uuid = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'report' => 'nullable|string|max:40', 'filters' => 'nullable|array', 'columns' => 'nullable|array', 'columns.*' => 'string|max:60', 'sort' => 'nullable|array', 'shared' => 'nullable|boolean']);
        $filters = array_intersect_key($d['filters'] ?? [], array_flip(ReportCatalogue::FILTERS));
        $view = $uuid ? SavedView::query()->where('module', 'attendance')->where('uuid', $uuid)->where('user_id', $r->user()->id)->firstOrFail() : new SavedView(['module' => 'attendance', 'user_id' => $r->user()->id]);
        $shared = (bool) ($d['shared'] ?? false);
        abort_if($shared && ! $r->user()->allows('attendance.manage'), 403, 'Only time-office administrators can share a view.');
        $view->fill(['name' => $d['name'], 'filters' => ['report' => $d['report'] ?? ($view->filters['report'] ?? 'daily')] + $filters, 'columns' => $d['columns'] ?? null, 'sort' => $d['sort'] ?? null, 'shared' => $shared])->save();

        return response()->json($this->view($view, $r), $uuid ? 200 : 201);
    }

    public function deleteView(Request $r, string $uuid)
    {
        SavedView::query()->where('module', 'attendance')->where('uuid', $uuid)->where('user_id', $r->user()->id)->firstOrFail()->delete();

        return response()->noContent();
    }

    private function view(SavedView $v, Request $r): array
    {
        return ['uuid' => $v->uuid, 'name' => $v->name, 'report' => $v->filters['report'] ?? 'daily', 'filters' => collect($v->filters)->except('report')->all(), 'columns' => $v->columns, 'sort' => $v->sort, 'shared' => $v->shared, 'mine' => $v->user_id === $r->user()->id];
    }

    // ---- search ----------------------------------------------------------------------------------
    public function search(Request $r)
    {
        $d = $r->validate(['q' => 'required|string|min:2|max:100']);
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $d['q']).'%';
        $t = Ops::tenantId();
        $out = ['query' => $d['q'], 'results' => []];
        $out['results']['employees'] = Ops::employees($r->user(), ['attendance.view', 'attendance.team.view', 'attendance.manage', 'attendance.reports.view'])
            ->where(fn ($w) => $w->where('p.display_name', 'like', $term)->orWhere('e.employee_number', 'like', $term))->orderBy('p.display_name')->limit(10)
            ->get(['e.uuid', 'e.employee_number', 'p.display_name'])->map(fn ($e) => ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name, 'href' => '/attendance/employees/'.$e->uuid])->all();
        if ($r->user()->allows('attendance.reports.view') || $r->user()->allows('attendance.manage')) {
            $out['results']['shifts'] = DB::table('shifts')->where('tenant_id', $t)->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term))->limit(5)->get(['id', 'name', 'code'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'href' => '/attendance/reports/shift?shift_id='.$s->id])->all();
            $out['results']['departments'] = DB::table('departments')->where('tenant_id', $t)->where('name', 'like', $term)->limit(5)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'href' => '/attendance/reports/daily?department_id='.$s->id])->all();
            $out['results']['locations'] = DB::table('locations')->where('tenant_id', $t)->where('name', 'like', $term)->limit(5)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'href' => '/attendance/reports/daily?location_id='.$s->id])->all();
            $out['results']['policies'] = DB::table('attendance_policies')->where('tenant_id', $t)->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term))->limit(5)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'href' => '/attendance/reports/daily?policy_id='.$s->id])->all();
            $out['results']['devices'] = DB::table('attendance_devices')->where('tenant_id', $t)->where('name', 'like', $term)->limit(5)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'href' => '/attendance/reports/device_punch?device_id='.$s->id])->all();
        }
        $out['filters_hint'] = 'Use /attendance/reports/{report} with filters: '.implode(', ', ReportCatalogue::FILTERS).'.';

        return $out;
    }

    public function rosterGaps(Request $r, Coverage $c)
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $d['date'] ?? \Carbon\CarbonImmutable::parse(Ops::today())->addDay()->toDateString();
        $people = Ops::employees($r->user(), ['attendance.manage', 'attendance.reports.view', 'attendance.roster.manage'], true)->get(['e.id', 'e.uuid', 'e.employee_number', 'p.display_name'])->keyBy('id');
        $gaps = array_keys(array_filter($c->shiftStates($people->keys()->map(fn ($v) => (int) $v)->all(), $date), fn ($s) => $s['state'] === 'unassigned'));

        return ['date' => $date, 'count' => count($gaps), 'data' => collect($gaps)->take(100)->map(fn ($id) => ['employee_uuid' => $people[$id]->uuid, 'employee_number' => $people[$id]->employee_number, 'name' => $people[$id]->display_name])->values()->all()];
    }
}
