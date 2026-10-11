<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Engine\Explainer;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceTrace;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DayReadController extends Controller
{
    private const SORTS = ['attendance_date', 'status', 'net_minutes', 'late_minutes', 'overtime_minutes', 'confidence', 'employee_number'];

    private function permission(Request $r): ?string
    {
        foreach (['attendance.manage', 'attendance.team.view'] as $p) {
            if ($r->user()->allows($p)) {
                return $p;
            }
        }

        return null;
    }

    /** Employee by uuid inside the viewer's reach (own record, or team/manage scope). 404 otherwise. */
    private function subject(Request $r, string $uuid): Employee
    {
        $e = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class);
        $perm = $this->permission($r);
        abort_unless($scope->isSelf($r->user(), $e) || ($perm && $scope->canAccess($r->user(), $e, $perm)), 404);

        return $e;
    }

    private function labels(): array
    {
        return AttendanceSettings::statusLabels();
    }

    public function daily(Request $r)
    {
        $perm = $this->permission($r);
        abort_unless($perm, 403, 'You do not have access to this area.');
        $q = AttendanceDay::query()->join('employees', 'employees.id', '=', 'attendance_days.employee_id')->join('people', 'people.id', '=', 'employees.person_id')
            ->select('attendance_days.*', 'employees.uuid as employee_uuid', 'employees.employee_number', 'people.display_name');
        app(DataScope::class)->constrain($q, $r->user(), $perm, 'attendance_days.employee_id');
        $from = $r->input('from', $r->input('date'));
        $to = $r->input('to', $r->input('date'));
        $q->when($from, fn ($x, $v) => $x->where('attendance_date', '>=', $v))->when($to, fn ($x, $v) => $x->where('attendance_date', '<=', $v));
        foreach (['status', 'confidence', 'shift_id', 'finalization'] as $f) {
            $q->when($r->input($f), fn ($x, $v) => $x->whereIn('attendance_days.'.$f, (array) $v));
        }
        $q->when($r->input('policy'), fn ($x, $v) => $x->whereIn('policy_version_id', DB::table('attendance_policy_versions')->join('attendance_policies', 'attendance_policies.id', '=', 'attendance_policy_versions.policy_id')->where('attendance_policies.uuid', $v)->pluck('attendance_policy_versions.id')));
        $q->when($r->input('search'), fn ($x, $v) => $x->where(fn ($w) => $w->where('people.display_name', 'like', '%'.$v.'%')->orWhere('employees.employee_number', 'like', '%'.$v.'%')));
        foreach (['department' => 'department_id', 'location' => 'location_id', 'legal_entity' => 'legal_entity_id'] as $k => $col) {
            $q->when($r->input($k), fn ($x, $v) => $x->whereExists(fn ($s) => $s->select(DB::raw(1))->from('employee_assignments as a')->whereColumn('a.employee_id', 'employees.id')->where('a.'.$col, $v)
                ->whereColumn('a.effective_from', '<=', 'attendance_days.attendance_date')->where(fn ($w) => $w->whereNull('a.effective_to')->orWhereColumn('a.effective_to', '>=', 'attendance_days.attendance_date'))));
        }
        $q->when($r->input('manager'), fn ($x, $v) => $x->whereExists(fn ($s) => $s->select(DB::raw(1))->from('employee_reporting_relationships as m')->join('employees as mg', 'mg.id', '=', 'm.manager_id')->whereColumn('m.employee_id', 'employees.id')
            ->where('mg.uuid', $v)->where('m.type', 'primary')->whereColumn('m.effective_from', '<=', 'attendance_days.attendance_date')->where(fn ($w) => $w->whereNull('m.effective_to')->orWhereColumn('m.effective_to', '>=', 'attendance_days.attendance_date'))));
        $q->when($r->input('exception_type'), fn ($x, $v) => $x->whereExists(fn ($s) => $s->select(DB::raw(1))->from('attendance_exceptions as x')->whereColumn('x.day_id', 'attendance_days.id')->where('x.type', $v)->whereIn('x.status', ['open', 'acknowledged'])));
        $q->when($r->input('source'), fn ($x, $v) => $x->whereExists(fn ($s) => $s->select(DB::raw(1))->from('attendance_events as ev')->whereColumn('ev.employee_id', 'attendance_days.employee_id')->whereColumn('ev.local_date', 'attendance_days.attendance_date')->where('ev.source', $v)->whereNull('ev.voided_at')));
        $q->when($r->input('device'), fn ($x, $v) => $x->whereExists(fn ($s) => $s->select(DB::raw(1))->from('attendance_events as ev')->join('attendance_devices as d', 'd.id', '=', 'ev.device_id')->whereColumn('ev.employee_id', 'attendance_days.employee_id')->whereColumn('ev.local_date', 'attendance_days.attendance_date')->where('d.uuid', $v)));
        $sort = in_array($r->input('sort'), self::SORTS, true) ? $r->input('sort') : 'attendance_date';
        $col = $sort === 'employee_number' ? 'employees.employee_number' : 'attendance_days.'.$sort;
        $page = $q->orderBy($col, $r->input('direction') === 'asc' ? 'asc' : 'desc')->orderBy('attendance_days.id')->paginate(min(100, max(5, (int) $r->input('per_page', 25))));

        return ['data' => $page->getCollection()->map(fn ($d) => $this->day($d) + ['employee' => ['uuid' => $d->employee_uuid, 'employee_number' => $d->employee_number, 'name' => $d->display_name]]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    private function day(AttendanceDay $d): array
    {
        return ['uuid' => $d->uuid, 'date' => substr($d->attendance_date, 0, 10), 'status' => $d->status, 'status_label' => AttendanceStatus::label($d->status, $this->labels()), 'confidence' => $d->confidence, 'finalization' => $d->finalization,
            'first_in' => Utc::iso($d->first_in), 'last_out' => Utc::iso($d->last_out), 'required_minutes' => $d->required_minutes, 'net_minutes' => $d->net_minutes, 'gross_minutes' => $d->gross_minutes, 'break_minutes' => $d->break_minutes,
            'late_minutes' => $d->late_minutes, 'early_exit_minutes' => $d->early_exit_minutes, 'overtime_minutes' => $d->overtime_minutes, 'approved_overtime_minutes' => $d->approved_overtime_minutes,
            'missing_punch' => (bool) $d->missing_punch, 'open_exceptions' => $d->open_exceptions, 'work_mode' => $d->work_mode, 'shift_id' => $d->shift_id, 'policy_version_id' => $d->policy_version_id,
            'engine_version' => $d->engine_version, 'calculation_version' => $d->calculation_version, 'calculated_at' => Utc::iso($d->calculated_at)];
    }

    public function myDays(Request $r)
    {
        $month = $r->input('month', CarbonImmutable::now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422, 'Use the format YYYY-MM for the month.');
        $own = app(DataScope::class)->ownEmployeeIds($r->user());
        $first = $month.'-01';
        $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();
        $days = AttendanceDay::query()->whereIn('employee_id', $own)->whereBetween('attendance_date', [$first, $last])->orderBy('attendance_date')->get();
        $pending = DB::table('attendance_regularizations')->where('tenant_id', $r->user()->tenant_id)->whereIn('employee_id', $own)->where('status', 'pending')->whereBetween('attendance_date', [$first, $last])->pluck('attendance_date')->map(fn ($x) => substr($x, 0, 10))->flip();
        $out = $days->map(function ($d) use ($pending) {
            $date = substr($d->attendance_date, 0, 10);
            $m = array_keys(array_filter(['late' => $d->late_minutes > 0, 'overtime' => $d->overtime_minutes > 0, 'missing_punch' => (bool) $d->missing_punch, 'regularization_pending' => $pending->has($date),
                'holiday' => $d->status === 'holiday', 'weekly_off' => $d->status === 'weekly_off', 'leave' => $d->status === 'leave']));

            return ['date' => $date, 'status' => $d->status, 'label' => AttendanceStatus::label($d->status, $this->labels()), 'net_minutes' => $d->net_minutes, 'first_in' => Utc::iso($d->first_in), 'last_out' => Utc::iso($d->last_out), 'markers' => $m];
        });

        return ['data' => ['month' => $month, 'days' => $out]];
    }

    public function show(Request $r, string $employeeUuid, string $date)
    {
        $e = $this->subject($r, $employeeUuid);
        $day = AttendanceDay::query()->where('employee_id', $e->id)->where('attendance_date', $date)->firstOrFail();
        $traces = AttendanceTrace::query()->where('day_id', $day->id)->orderByDesc('calculation_version')->get();
        $latest = $traces->first();
        $explanation = $latest ? (json_decode($latest->explanation, true) ?: ['headline' => $latest->explanation]) : null;
        $label = $day->policy_version_id ? \App\Domain\Attendance\Models\AttendancePolicyVersion::query()->with('policy')->find($day->policy_version_id)?->label() : null;
        $exceptions = DB::table('attendance_exceptions')->where('tenant_id', $e->tenant_id)->where('employee_id', $e->id)->where('attendance_date', $date)->orderBy('id')->get(['uuid', 'type', 'severity', 'status', 'details'])
            ->map(fn ($x) => ['uuid' => $x->uuid, 'type' => $x->type, 'severity' => $x->severity, 'status' => $x->status, 'priority_reason' => (json_decode($x->details, true) ?: [])['priority_reason'] ?? null]);

        return ['data' => $this->day($day) + ['employee_uuid' => $e->uuid, 'policy' => $label, 'explanation' => $explanation, 'exceptions' => $exceptions,
            'calculation_history' => $traces->map(fn ($t) => ['calculation_version' => $t->calculation_version, 'engine_version' => $t->engine_version, 'trigger' => $t->trigger, 'status' => $t->result['status'] ?? null, 'at' => Utc::iso($t->created_at)])->values()]];
    }

    public function trace(Request $r, string $employeeUuid, string $date)
    {
        abort_unless($r->user()->allows('attendance.manage'), 403, 'You do not have access to this area.');
        $e = $this->subject($r, $employeeUuid);
        $day = AttendanceDay::query()->where('employee_id', $e->id)->where('attendance_date', $date)->firstOrFail();
        $v = $r->input('version');
        $t = AttendanceTrace::query()->where('day_id', $day->id)->when($v, fn ($q) => $q->where('calculation_version', $v))->orderByDesc('calculation_version')->firstOrFail();

        return ['data' => ['calculation_version' => $t->calculation_version, 'engine_version' => $t->engine_version, 'trigger' => $t->trigger, 'inputs' => $t->inputs, 'steps' => $t->steps, 'result' => $t->result,
            'explanation' => json_decode($t->explanation, true) ?: $t->explanation, 'created_at' => Utc::iso($t->created_at)]];
    }

    public function reconstruction(Request $r, string $employeeUuid, string $date)
    {
        $e = $this->subject($r, $employeeUuid);
        $day = AttendanceDay::query()->where('employee_id', $e->id)->where('attendance_date', $date)->firstOrFail();
        $tenant = $e->tenant_id;
        $tz = $day->shift_id ? (DB::table('shifts')->where('id', $day->shift_id)->value('timezone') ?: $e->timezone) : $e->timezone;
        $precise = $r->user()->allows('attendance.location.view');
        $events = [];
        $punches = DB::table('attendance_events')->where('tenant_id', $tenant)->where('employee_id', $e->id)->whereNull('voided_at')
            ->where('punched_at', '>=', Utc::str(CarbonImmutable::parse($date, 'UTC')->subDay()))->where('punched_at', '<=', Utc::str(CarbonImmutable::parse($date, 'UTC')->addDays(2)))->orderBy('punched_at')->orderBy('id')->get();
        $trace = AttendanceTrace::query()->where('day_id', $day->id)->orderByDesc('calculation_version')->first();
        $used = $trace ? ($trace->result['punch_ids'] ?? []) : [];
        foreach ($punches as $p) {
            $local = CarbonImmutable::parse($p->punched_at, 'UTC')->setTimezone($tz)->toDateString();
            if ($used ? ! in_array('event:'.$p->id, $used, true) : $local !== $date) {
                continue;
            }
            $meta = ['source' => $p->source, 'direction' => $p->direction, 'geofence_status' => $p->geofence_status, 'event_id' => $p->id];
            if ($precise && $p->latitude !== null) {
                $meta['latitude'] = (float) $p->latitude;
                $meta['longitude'] = (float) $p->longitude;
                $meta['accuracy_meters'] = $p->accuracy_meters;
            }
            $events[] = ['at' => Utc::parse($p->punched_at), 'kind' => 'punch', 'label' => ucfirst($p->source).' '.$p->direction, 'detail' => $p->geofence_status !== 'not_checked' ? 'Location check: '.str_replace('_', ' ', $p->geofence_status) : null] + $meta;
        }
        foreach (AttendanceTrace::query()->where('day_id', $day->id)->orderBy('calculation_version')->get() as $t) {
            $events[] = ['at' => Utc::parse($t->created_at), 'kind' => 'calculation', 'label' => $t->calculation_version === 1 ? 'Attendance calculated' : 'Attendance recalculated', 'detail' => 'Result: '.($t->result['status'] ?? '?').' ('.$t->trigger.')', 'calculation_version' => $t->calculation_version];
        }
        foreach (DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('employee_id', $e->id)->where('attendance_date', $date)->get() as $g) {
            $events[] = ['at' => Utc::parse($g->created_at), 'kind' => 'regularization', 'label' => 'Regularization submitted', 'detail' => $g->reason, 'status' => $g->status];
            if ($g->decided_at) {
                $events[] = ['at' => Utc::parse($g->decided_at), 'kind' => 'regularization', 'label' => 'Regularization '.$g->status, 'detail' => null];
            }
        }
        foreach (DB::table('attendance_adjustments')->where('tenant_id', $tenant)->where('employee_id', $e->id)->where('attendance_date', $date)->get() as $a) {
            $events[] = ['at' => Utc::parse($a->created_at), 'kind' => 'adjustment', 'label' => 'Adjustment: '.str_replace('_', ' ', $a->type), 'detail' => $a->reason, 'status' => $a->status];
        }
        foreach (AttendanceDay::query()->where('id', $day->id)->whereNotNull('lock_id')->get() as $d) {
            $lock = DB::table('attendance_locks')->where('id', $d->lock_id)->first();
            if ($lock) {
                $events[] = ['at' => Utc::parse($lock->locked_at), 'kind' => 'lock', 'label' => 'Attendance locked', 'detail' => $lock->reason];
            }
        }
        foreach (DomainEvent::query()->where('employee_id', $e->id)->whereIn('type', ['AttendanceCalculated', 'AttendanceExceptionDetected', 'AttendanceAdjusted', 'RegularizationApproved', 'RegularizationSubmitted'])->where('effective_date', $date)->get() as $ev) {
            if ($ev->type !== 'AttendanceCalculated') {
                $events[] = ['at' => CarbonImmutable::instance($ev->occurred_at), 'kind' => 'event', 'label' => $ev->summary, 'detail' => $ev->type];
            }
        }

        return ['data' => ['date' => $date, 'timezone' => $tz, 'precise_location' => $precise, 'timeline' => Explainer::dayReconstruction($events, $tz, $date)]];
    }
}
