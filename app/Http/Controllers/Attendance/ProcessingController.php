<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Locks\LockService;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Domain\Attendance\Models\ProcessingRun;
use App\Domain\Attendance\Processing\ExceptionService;
use App\Domain\Attendance\Processing\PolicySimulation;
use App\Domain\Attendance\Processing\RecalculationService;
use App\Domain\Attendance\Services\Utc;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingController extends Controller
{
    // ---- processing runs ----------------------------------------------------------------------

    private function scopeFrom(Request $r): array
    {
        $r->validate(['scope' => 'required|array', 'from' => 'required|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $s = $r->input('scope');
        $scope = ['from' => $r->input('from'), 'to' => $r->input('to', $r->input('from'))];
        if (! empty($s['employee_uuids'])) {
            $scope['employee_ids'] = Employee::query()->whereIn('uuid', (array) $s['employee_uuids'])->pluck('id')->all();
            if (! $scope['employee_ids']) {
                throw ValidationException::withMessages(['scope' => 'We could not find those employees.']);
            }
        }
        foreach (['department_id', 'location_id', 'employee_group_id', 'legal_entity_id', 'payroll_group_id', 'tenant'] as $k) {
            if (! empty($s[$k])) {
                $scope[$k] = $s[$k];
            }
        }

        return $scope;
    }

    public function process(Request $r, RecalculationService $service)
    {
        $uuid = $service->recalculate($this->scopeFrom($r), 'manual', $r->user()->id, $r->input('dedupe_key'));

        return response()->json(['data' => $this->run(ProcessingRun::byUuid($uuid))], 202);
    }

    public function preview(Request $r, PolicySimulation $sim)
    {
        return ['data' => $sim->impact($this->scopeFrom($r))];
    }

    public function runs(Request $r)
    {
        $rows = ProcessingRun::query()->orderByDesc('id')->limit(50)->get();

        return ['data' => $rows->map(fn ($x) => $this->run($x, false))];
    }

    public function showRun(string $uuid)
    {
        return ['data' => $this->run(ProcessingRun::byUuid($uuid))];
    }

    private function run(ProcessingRun $x, bool $full = true): array
    {
        return ['uuid' => $x->uuid, 'trigger' => $x->trigger, 'status' => $x->status, 'scope' => $x->scope, 'total_days' => $x->total_days, 'processed_days' => $x->processed_days, 'failed_days' => $x->failed_days,
            'skipped_locked' => $x->skipped_locked, 'started_at' => Utc::iso($x->started_at), 'finished_at' => Utc::iso($x->finished_at), 'created_at' => Utc::iso($x->created_at)] + ($full ? ['errors' => $x->errors ?? []] : []);
    }

    // ---- exceptions ---------------------------------------------------------------------------

    private function exceptionQuery(Request $r)
    {
        $q = AttendanceException::query()->join('employees', 'employees.id', '=', 'attendance_exceptions.employee_id')->join('people', 'people.id', '=', 'employees.person_id')->select('attendance_exceptions.*');
        $perm = $r->user()->allows('attendance.manage') ? 'attendance.manage' : 'attendance.team.view';
        abort_unless($r->user()->allows($perm), 403, 'You do not have access to this area.');
        app(DataScope::class)->constrain($q, $r->user(), $perm, 'attendance_exceptions.employee_id');
        foreach (['type', 'severity', 'status'] as $f) {
            $q->when($r->input($f), fn ($qq, $v) => $qq->whereIn('attendance_exceptions.'.$f, (array) $v));
        }
        $q->when($r->input('from'), fn ($qq, $v) => $qq->where('attendance_date', '>=', $v))->when($r->input('to'), fn ($qq, $v) => $qq->where('attendance_date', '<=', $v));
        $q->when($r->input('employee'), fn ($qq, $v) => $qq->where('employees.uuid', $v));
        foreach (['department' => 'department_id', 'location' => 'location_id'] as $k => $col) {
            $q->when($r->input($k), fn ($qq, $v) => $qq->whereExists(fn ($s) => $s->select(DB::raw(1))->from('employee_assignments as a')->whereColumn('a.employee_id', 'employees.id')->where('a.'.$col, $v)
                ->whereColumn('a.effective_from', '<=', 'attendance_exceptions.attendance_date')->where(fn ($w) => $w->whereNull('a.effective_to')->orWhereColumn('a.effective_to', '>=', 'attendance_exceptions.attendance_date'))));
        }

        return $q;
    }

    private function exception(AttendanceException $e): array
    {
        $emp = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $e->employee_id)->first(['employees.uuid', 'employees.employee_number', 'people.display_name']);

        return ['uuid' => $e->uuid, 'type' => $e->type, 'title' => $e->details['title'] ?? \App\Domain\Attendance\Support\ExceptionType::title($e->type), 'severity' => $e->severity, 'status' => $e->status, 'attendance_date' => substr($e->attendance_date, 0, 10),
            'source_rule' => $e->source_rule, 'detected_at' => Utc::iso($e->detected_at), 'resolved_at' => Utc::iso($e->resolved_at), 'resolution' => $e->resolution, 'priority_reason' => $e->details['priority_reason'] ?? null,
            'details' => $e->details, 'employee' => ['uuid' => $emp->uuid, 'employee_number' => $emp->employee_number, 'name' => $emp->display_name]];
    }

    public function exceptions(Request $r)
    {
        $page = $this->exceptionQuery($r)->orderByRaw("case attendance_exceptions.severity when 'critical' then 4 when 'action_required' then 3 when 'warning' then 2 else 1 end desc")
            ->orderByDesc('attendance_exceptions.attendance_date')->orderBy('attendance_exceptions.id')->paginate(min(100, max(5, (int) $r->input('per_page', 25))));

        return ['data' => $page->getCollection()->map(fn ($e) => $this->exception($e)), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function exceptionAction(Request $r, ExceptionService $service, string $uuid, string $action)
    {
        $e = $this->exceptionQuery($r)->where('attendance_exceptions.uuid', $uuid)->firstOrFail();
        abort_unless($r->user()->allows('attendance.manage') || $r->user()->allows('attendance.approve'), 403, 'You do not have access to this area.');

        return ['data' => $this->exception($service->transition($e, $action, $r->user(), $r->input('resolution', $r->input('note'))))];
    }

    // ---- locks --------------------------------------------------------------------------------

    private function lock(AttendanceLock $l, LockService $svc): array
    {
        return ['uuid' => $l->uuid, 'scope_type' => $l->scope_type, 'scope_id' => $l->scope_id, 'scope_label' => app(\App\Domain\Attendance\Locks\DatabaseLockGuard::class)->label($l->scope_type, $l->scope_id), 'start_date' => substr($l->start_date, 0, 10),
            'end_date' => substr($l->end_date, 0, 10), 'status' => $l->status, 'reason' => $l->reason, 'locked_at' => Utc::iso($l->locked_at), 'unlock_requested' => (bool) $l->unlock_requested_by, 'unlock_reason' => $l->unlock_reason,
            'unlocked_at' => Utc::iso($l->unlocked_at)] + (isset($l->days_locked) ? ['days_locked' => $l->days_locked] : []) + (isset($l->days_reopened) ? ['days_reopened' => $l->days_reopened] : []);
    }

    public function locks(LockService $svc)
    {
        return ['data' => AttendanceLock::query()->orderByDesc('id')->limit(100)->get()->map(fn ($l) => $this->lock($l, $svc))];
    }

    public function lockPreview(Request $r, LockService $svc)
    {
        return ['data' => $svc->preview($r->all())];
    }

    public function approveDays(Request $r, LockService $svc)
    {
        return ['data' => $svc->approveDays($r->all())];
    }

    public function lockStore(Request $r, LockService $svc)
    {
        return response()->json(['data' => $this->lock($svc->create($r->user(), $r->all()), $svc)], 201);
    }

    public function unlockRequest(Request $r, LockService $svc, string $uuid)
    {
        return ['data' => $this->lock($svc->requestUnlock(AttendanceLock::byUuid($uuid), $r->user(), (string) $r->input('reason', '')), $svc)];
    }

    public function unlockApprove(Request $r, LockService $svc, string $uuid)
    {
        return ['data' => $this->lock($svc->approveUnlock(AttendanceLock::byUuid($uuid), $r->user()), $svc)];
    }

    public function unlockReject(Request $r, LockService $svc, string $uuid)
    {
        return ['data' => $this->lock($svc->rejectUnlock(AttendanceLock::byUuid($uuid), $r->user(), $r->input('comment')), $svc)];
    }
}
