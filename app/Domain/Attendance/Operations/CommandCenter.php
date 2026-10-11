<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Support\AttendanceStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * TIME OFFICE COMMAND CENTER: one operational read model of "what needs the time office today".
 * Every section has {count, sample, action}; the action opens the affected records. People data is limited by DataScope;
 * tenant-level sections (processing runs, devices) are shown to time-office operators (attendance.manage / attendance.process).
 */
final class CommandCenter
{
    public const PERMISSIONS = ['attendance.manage', 'attendance.process', 'attendance.reports.view'];

    private const OFF = [AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, AttendanceStatus::NOT_SCHEDULED, AttendanceStatus::LEAVE];

    public function __construct(private Coverage $coverage, private TeamAttendance $team) {}

    public function build(User $user, ?string $date = null): array
    {
        $date ??= Ops::today();
        $tenant = Ops::tenantId();
        $tomorrow = CarbonImmutable::parse($date)->addDay()->toDateString();
        $yesterday = CarbonImmutable::parse($date)->subDay()->toDateString();
        $operator = $user->allows('attendance.manage') || $user->allows('attendance.process');

        $people = Ops::employees($user, self::PERMISSIONS, true)->select('e.id', 'e.uuid', 'e.employee_number', 'p.display_name')->orderBy('p.display_name')->get()->keyBy('id');
        $ids = $people->keys()->map(fn ($v) => (int) $v)->all();
        $ref = fn (int $id, array $extra = []) => ['employee_uuid' => $people[$id]->uuid, 'employee_number' => $people[$id]->employee_number, 'name' => $people[$id]->display_name] + $extra;
        $sample = fn (array $rows) => array_slice($rows, 0, 10);

        $days = DB::table('attendance_days')->where('tenant_id', $tenant)->where('attendance_date', $date)->whereIn('employee_id', $ids ?: [0])->get()->keyBy('employee_id');
        $sections = [];

        // 1. processing health ------------------------------------------------------------------------------
        if ($operator) {
            $runs = DB::table('attendance_processing_runs')->where('tenant_id', $tenant)->orderByDesc('id')->limit(5)->get(['uuid', 'trigger', 'status', 'total_days', 'processed_days', 'failed_days', 'skipped_locked', 'started_at', 'finished_at', 'created_at', 'errors']);
            $failed = DB::table('attendance_processing_runs')->where('tenant_id', $tenant)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subDays(7))->count();
            $stale = DB::table('attendance_events')->where('tenant_id', $tenant)->where('processing_state', 'pending')->whereNull('voided_at')->where('punched_at', '<', now()->subMinutes(30))
                ->whereIn('employee_id', $ids ?: [0])->select('employee_id', 'local_date')->distinct()->get()->count();
            $missingYesterday = $this->unprocessedDay($ids, $yesterday);
            $sections['processing_health'] = $this->section('Attendance processing health', $failed + (int) $stale + count($missingYesterday), [
                'recent_runs' => $runs->map(fn ($r) => ['uuid' => $r->uuid, 'trigger' => $r->trigger, 'status' => $r->status, 'total_days' => $r->total_days, 'processed_days' => $r->processed_days, 'failed_days' => $r->failed_days,
                    'skipped_locked' => $r->skipped_locked, 'started_at' => Ops::iso($r->started_at), 'finished_at' => Ops::iso($r->finished_at), 'note' => $this->friendly($r->errors)])->all(),
                'failed_runs_7d' => $failed, 'unprocessed_punch_days' => (int) $stale, 'yesterday_without_calculation' => count($missingYesterday),
                'sample' => $sample(array_map(fn ($id) => $ref($id, ['date' => $yesterday, 'issue' => 'No calculated attendance for the day']), $missingYesterday)),
            ], 'Open processing', '/attendance/time-office/processing', 'GET', '/api/v1/attendance/processing/runs');
        }

        // 2. scheduled today --------------------------------------------------------------------------------
        $noRow = array_values(array_filter($ids, fn ($id) => ! isset($days[$id])));
        $states = $this->coverage->shiftStates($ids, $date);
        $scheduled = [];
        foreach ($ids as $id) {
            $d = $days[$id] ?? null;
            if ($d ? (($d->scheduled_start || $d->shift_id) && ! in_array($d->status, self::OFF, true)) : (($states[$id]['state'] ?? '') === 'working')) {
                $scheduled[] = $id;
            }
        }
        $sections['scheduled_today'] = $this->section('Employees scheduled today', count($scheduled), ['of_employees' => count($ids), 'sample' => $sample(array_map(fn ($id) => $ref($id), $scheduled))],
            'Open daily attendance', '/attendance/time-office/daily?date='.$date, 'GET', '/api/v1/attendance/reports/daily?date='.$date);

        // 3. currently checked in ---------------------------------------------------------------------------
        $last = $this->team->lastEvents($ids, $date);
        $in = array_keys(array_filter($last, fn ($e) => $e->direction === 'IN'));
        $sections['checked_in'] = $this->section('Currently checked in', count($in), ['sample' => $sample(array_map(fn ($id) => $ref($id), $in))],
            'See who is in', '/attendance/time-office/daily?date='.$date.'&state=in', 'GET', '/api/v1/attendance/team/today?date='.$date);

        // 4. missing punches / 5. late ------------------------------------------------------------------------
        $missing = $days->filter(fn ($d) => $d->missing_punch || $d->status === AttendanceStatus::MISSING_PUNCH)->keys()->map(fn ($v) => (int) $v)->all();
        $sections['missing_punches'] = $this->section('Missing punches', count($missing), ['sample' => $sample(array_map(fn ($id) => $ref($id, ['date' => $date]), $missing))],
            'Resolve missing punches', '/attendance/time-office/missing-punches?date='.$date, 'GET', '/api/v1/attendance/reports/missing_punch?from='.$date.'&to='.$date);
        $late = $days->filter(fn ($d) => (int) $d->late_minutes > 0)->sortByDesc('late_minutes');
        $sections['late_arrivals'] = $this->section('Late arrivals', $late->count(), ['sample' => $sample($late->map(fn ($d) => $ref((int) $d->employee_id, ['late_minutes' => (int) $d->late_minutes]))->values()->all())],
            'Review late arrivals', '/attendance/time-office/late?date='.$date, 'GET', '/api/v1/attendance/reports/late_arrival?from='.$date.'&to='.$date);

        // 6. unscheduled today and tomorrow, roster gaps ------------------------------------------------------
        $unscheduledToday = array_values(array_filter($noRow, fn ($id) => ($states[$id]['state'] ?? '') === 'unassigned'));
        $unscheduledToday = array_values(array_unique(array_merge($unscheduledToday, array_filter($ids, fn ($id) => isset($days[$id]) && ! $days[$id]->shift_id && ! in_array($days[$id]->status, self::OFF, true)))));
        $sections['unscheduled_today'] = $this->section('Employees without a shift today', count($unscheduledToday), ['sample' => $sample(array_map(fn ($id) => $ref($id), $unscheduledToday))],
            'Assign shifts', '/attendance/rosters?date='.$date, 'GET', '/api/v1/attendance/roster-gaps?date='.$date);
        $tomorrowStates = $this->coverage->shiftStates($ids, $tomorrow);
        $noShiftTomorrow = array_keys(array_filter($tomorrowStates, fn ($s) => $s['state'] === 'unassigned'));
        $sections['unscheduled_tomorrow'] = $this->section('Employees without a shift tomorrow', count($noShiftTomorrow), ['date' => $tomorrow, 'sample' => $sample(array_map(fn ($id) => $ref($id), $noShiftTomorrow))],
            'Assign shifts before tomorrow', '/attendance/rosters?date='.$tomorrow, 'GET', '/api/v1/attendance/roster-gaps?date='.$tomorrow);
        $byDate = [$tomorrow => count($noShiftTomorrow)];
        $gapTotal = count($noShiftTomorrow);
        for ($i = 2; $i <= 7; $i++) {
            $d = CarbonImmutable::parse($date)->addDays($i)->toDateString();
            $byDate[$d] = count(array_filter($this->coverage->shiftStates($ids, $d), fn ($s) => $s['state'] === 'unassigned'));
            $gapTotal += $byDate[$d];
        }
        $sections['roster_gaps'] = $this->section('Roster gaps in the next 7 days', $gapTotal, ['by_date' => $byDate, 'unit' => 'employee-days'],
            'Fill roster gaps', '/attendance/rosters?from='.$tomorrow, 'GET', '/api/v1/attendance/roster-gaps?date='.$tomorrow);

        // 7. devices ----------------------------------------------------------------------------------------------
        if ($operator) {
            $devices = DB::table('attendance_devices')->where('tenant_id', $tenant)->whereIn('status', ['active', 'error'])->get(['uuid', 'name', 'status', 'last_sync_at', 'last_sync_status', 'sync_interval_minutes']);
            $late2x = $devices->filter(fn ($d) => $d->status === 'error' || ! $d->last_sync_at || CarbonImmutable::parse($d->last_sync_at, 'UTC')->lessThan(now()->subMinutes(2 * max(1, (int) $d->sync_interval_minutes))));
            $sections['device_sync'] = $this->section('Devices not synced', $late2x->count(), ['sample' => $late2x->take(10)->map(fn ($d) => ['device_uuid' => $d->uuid, 'name' => $d->name, 'status' => $d->status, 'last_sync_at' => Ops::iso($d->last_sync_at),
                'expected_every_minutes' => (int) $d->sync_interval_minutes])->values()->all(), 'rule' => 'Not synced for more than twice the configured interval.'], 'Check devices', '/attendance/devices', 'GET', '/api/v1/attendance/devices');
        }

        // 8. pending approvals ----------------------------------------------------------------------------------
        $regs = DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $ids ?: [0])->orderBy('attendance_date')->get(['uuid', 'employee_id', 'attendance_date', 'type']);
        $sections['pending_regularizations'] = $this->section('Regularizations awaiting approval', $regs->count(), ['sample' => $regs->take(10)->map(fn ($r) => $ref((int) $r->employee_id, ['uuid' => $r->uuid, 'date' => $r->attendance_date, 'type' => $r->type]))->all()],
            'Review regularizations', '/attendance/time-office/approvals?kind=regularization', 'GET', '/api/v1/attendance/reports/regularization?status=pending');
        $ot = DB::table('overtime_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $ids ?: [0])->orderBy('work_date')->get(['uuid', 'employee_id', 'work_date', 'requested_minutes']);
        $sections['overtime_awaiting_approval'] = $this->section('Overtime awaiting approval', $ot->count(), ['requested_minutes' => (int) $ot->sum('requested_minutes'),
            'sample' => $ot->take(10)->map(fn ($r) => $ref((int) $r->employee_id, ['uuid' => $r->uuid, 'date' => $r->work_date, 'requested_minutes' => (int) $r->requested_minutes]))->all()],
            'Review overtime', '/attendance/time-office/approvals?kind=overtime', 'GET', '/api/v1/attendance/reports/overtime?status=pending');

        // 9. locks / payroll cutoff -----------------------------------------------------------------------------
        $locks = DB::table('attendance_locks')->where('tenant_id', $tenant)->where('status', 'locked')->where('end_date', '>=', CarbonImmutable::parse($date)->subDays(62)->toDateString())->orderByDesc('end_date')->get(['uuid', 'scope_type', 'scope_id', 'start_date', 'end_date', 'locked_at']);
        $cutoff = PayrollCutoff::state($date, PayrollCutoff::day());
        $unfinalized = $cutoff['configured'] ? PayrollCutoff::unfinalized($cutoff['period_from'], $cutoff['period_to'], $ids) : null;
        $sections['attendance_locks'] = $this->section('Attendance lock status', $locks->count(), [
            'active_locks' => $locks->take(10)->map(fn ($l) => ['uuid' => $l->uuid, 'scope' => $l->scope_type, 'scope_id' => $l->scope_id, 'from' => $l->start_date, 'to' => $l->end_date, 'locked_at' => Ops::iso($l->locked_at)])->all(),
            'date_locked' => $locks->contains(fn ($l) => $l->scope_type === 'tenant' && $l->start_date <= $date && $l->end_date >= $date),
            'payroll_cutoff' => $cutoff + ['unfinalized' => $unfinalized],
        ], 'Review locks', '/attendance/time-office/locks', 'GET', '/api/v1/attendance/locks');

        return ['date' => $date, 'generated_at' => Ops::iso(now()), 'employees_in_view' => count($ids), 'sections' => $sections];
    }

    /** Employees expected to work on the date with no calculated attendance row. @return array<int,int> */
    private function unprocessedDay(array $ids, string $date): array
    {
        $have = DB::table('attendance_days')->where('tenant_id', Ops::tenantId())->where('attendance_date', $date)->whereIn('employee_id', $ids ?: [0])->pluck('employee_id')->flip();
        $rest = array_values(array_filter($ids, fn ($id) => ! isset($have[$id])));
        $states = $this->coverage->shiftStates($rest, $date);

        return array_values(array_filter($rest, fn ($id) => ($states[$id]['state'] ?? '') === 'working'));
    }

    private function section(string $title, int $count, array $data, string $label, string $href, string $method, string $endpoint): array
    {
        return ['title' => $title, 'count' => $count] + $data + ['action' => ['label' => $label, 'kind' => 'navigate', 'href' => $href, 'api' => ['method' => $method, 'endpoint' => $endpoint]]];
    }

    /** Friendly, short text for a run's error payload (never raw exceptions or traces). */
    private function friendly(mixed $errors): ?string
    {
        $errors = is_string($errors) ? json_decode($errors, true) : $errors;
        if (! $errors) {
            return null;
        }
        $text = is_array($errors) ? implode('; ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), array_slice($errors, 0, 3))) : (string) $errors;

        return mb_substr(preg_replace('/\s+/', ' ', strtok($text, "\n") ?: ''), 0, 200);
    }
}
