<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Support\AttendanceStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payroll readiness and payroll-ready output. No salary maths, no scoring.
 *
 * READINESS is derived ONLY from explicit unresolved conditions, each with a count and the affected employees:
 *   attendance_processed  employee-days in the period that have no calculated attendance_days row yet
 *   exceptions_remaining  open/acknowledged exceptions of severity action_required or critical (warnings/info never block)
 *   approvals_pending     regularizations, on-duty requests and adjustments still waiting for a decision
 *   attendance_locked     calculated employee-days that are not locked yet
 *   overtime_approved     overtime requests still waiting for a decision
 *   missing_policies      employees with no attendance policy in force at the end of the period
 *   missing_shifts        employee-days that expect work but have no shift
 * READY means every condition has a count of zero; otherwise NOT READY. The resolved percentage is only reported next to the
 * list of unresolved employees.
 *
 * OUTPUT comes ONLY from finalized (approved or locked) days unless include_open is requested, and is then flagged.
 */
final class PayrollReadiness
{
    public const PERMISSIONS = ['attendance.manage', 'attendance.process', 'attendance.reports.view'];

    private const OFF_STATUSES = [AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, AttendanceStatus::NOT_SCHEDULED, AttendanceStatus::LEAVE];

    public function __construct(private Coverage $coverage) {}

    /** @return int|null payroll group id by id or code; aborts 404 for an unknown group in this tenant */
    public function groupId(?string $group): ?int
    {
        if ($group === null || $group === '') {
            return null;
        }
        $row = DB::table('payroll_groups')->where('tenant_id', Ops::tenantId())->where(fn ($w) => $w->where('code', $group)->orWhere('id', ctype_digit($group) ? (int) $group : 0))->first();
        abort_unless($row, 404, 'That payroll group was not found.');

        return (int) $row->id;
    }

    /** Employees employed during the period within the viewer's reach (and the payroll group, when given). */
    public function employees(User $user, ?int $groupId, string $from, string $to): Builder
    {
        $q = Ops::employees($user, self::PERMISSIONS)->where('e.joined_on', '<=', $to)->where(fn ($w) => $w->whereNull('e.separated_on')->orWhere('e.separated_on', '>=', $from))
            ->whereNotIn('e.status', ['preboarding']);
        if ($groupId) {
            $q->whereExists(function ($s) use ($groupId, $from, $to) {
                $s->from('employee_policy_references as r')->whereColumn('r.employee_id', 'e.id')->where('r.kind', 'payroll_group')->where('r.reference_id', $groupId)
                    ->where('r.effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $from));
            });
        }

        return $q;
    }

    public function readiness(User $user, ?string $group, string $from, string $to): array
    {
        $groupId = $this->groupId($group);
        $today = Ops::today();
        $tenant = Ops::tenantId();
        $employees = $this->employees($user, $groupId, $from, $to)->select('e.id', 'e.uuid', 'e.employee_number', 'e.joined_on', 'e.separated_on', 'p.display_name')->orderBy('e.id')->get()->keyBy('id');
        $ids = $employees->keys()->map(fn ($v) => (int) $v)->all();

        // expected employee-days: every date of the period (up to today) inside the employment
        $expected = [];
        $totalExpected = 0;
        foreach ($employees as $id => $e) {
            $start = max($from, $e->joined_on);
            $end = min($to, $today, $e->separated_on ?: $to);
            $expected[$id] = $start <= $end ? (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1 : 0;
            $totalExpected += $expected[$id];
        }
        $rows = [];
        $dayRows = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])
            ->select('employee_id', 'attendance_date', 'status', 'shift_id', 'policy_version_id', 'finalization', 'open_exceptions', 'confidence')->get();
        $existing = [];
        foreach ($dayRows as $d) {
            $existing[(int) $d->employee_id] = ($existing[(int) $d->employee_id] ?? 0) + 1;
        }

        $conditions = [];
        // 1. processed
        $unprocessed = [];
        foreach ($expected as $id => $count) {
            $missing = $count - ($existing[$id] ?? 0);
            if ($missing > 0) {
                $unprocessed[$id] = $missing;
            }
        }
        $conditions[] = $this->condition('attendance_processed', 'Attendance processed', array_sum($unprocessed), 'employee-days', $employees, array_keys($unprocessed), $unprocessed,
            'Process attendance for these employees', '/attendance/time-office/processing', ['method' => 'POST', 'endpoint' => '/api/v1/attendance/processing/runs']);

        // 2. exceptions remaining (blocking severities only)
        $blocking = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])
            ->whereIn('status', ['open', 'acknowledged'])->whereIn('severity', ['action_required', 'critical'])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
        $nonBlocking = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])
            ->whereIn('status', ['open', 'acknowledged'])->whereIn('severity', ['info', 'warning'])->count();
        $conditions[] = $this->condition('exceptions_remaining', 'Exceptions remaining', array_sum($blocking), 'exceptions', $employees, array_keys($blocking), $blocking,
            'Resolve these exceptions', '/attendance/time-office/exceptions?from='.$from.'&to='.$to.'&blocking=1', ['method' => 'GET', 'endpoint' => '/api/v1/attendance/reports/exceptions?from='.$from.'&to='.$to]);

        // 3. approvals pending
        $pending = [];
        foreach (DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('status', 'pending')->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->get() as $r) {
            $pending[(int) $r->employee_id] = ($pending[(int) $r->employee_id] ?? 0) + (int) $r->c;
        }
        foreach (DB::table('on_duty_requests')->where('tenant_id', $tenant)->where('status', 'pending')->where('start_date', '<=', $to)->where('end_date', '>=', $from)->whereIn('employee_id', $ids ?: [0])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->get() as $r) {
            $pending[(int) $r->employee_id] = ($pending[(int) $r->employee_id] ?? 0) + (int) $r->c;
        }
        foreach (DB::table('attendance_adjustments')->where('tenant_id', $tenant)->where('status', 'pending_approval')->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->get() as $r) {
            $pending[(int) $r->employee_id] = ($pending[(int) $r->employee_id] ?? 0) + (int) $r->c;
        }
        $conditions[] = $this->condition('approvals_pending', 'Approvals pending', array_sum($pending), 'requests', $employees, array_keys($pending), $pending,
            'Review pending approvals', '/attendance/time-office/approvals', ['method' => 'GET', 'endpoint' => '/api/v1/attendance/reports/regularization?status=pending&from='.$from.'&to='.$to]);

        // 4. locked
        $notLocked = [];
        foreach ($dayRows as $d) {
            if ($d->finalization !== 'locked') {
                $notLocked[(int) $d->employee_id] = ($notLocked[(int) $d->employee_id] ?? 0) + 1;
            }
        }
        $conditions[] = $this->condition('attendance_locked', 'Attendance locked', array_sum($notLocked), 'employee-days', $employees, array_keys($notLocked), $notLocked,
            'Lock the period once everything above is resolved', '/attendance/time-office/locks', ['method' => 'POST', 'endpoint' => '/api/v1/attendance/locks']);

        // 5. overtime approved
        $overtime = DB::table('overtime_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereBetween('work_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
        $conditions[] = $this->condition('overtime_approved', 'Overtime approved', array_sum($overtime), 'requests', $employees, array_keys($overtime), $overtime,
            'Decide pending overtime requests', '/attendance/time-office/overtime?status=pending', ['method' => 'GET', 'endpoint' => '/api/v1/attendance/reports/overtime?from='.$from.'&to='.$to]);

        // 6. policies
        $reference = min($to, $today);
        $withPolicy = $this->coverage->policies($ids, $reference);
        $noPolicy = [];
        foreach ($ids as $id) {
            if (! isset($withPolicy[$id]) && ($expected[$id] ?? 0) > 0) {
                $noPolicy[$id] = 1;
            }
        }
        $conditions[] = $this->condition('missing_policies', 'Missing policies', count($noPolicy), 'employees', $employees, array_keys($noPolicy), $noPolicy,
            'Assign an attendance policy', '/attendance/policies/assignments', ['method' => 'POST', 'endpoint' => '/api/v1/attendance/policies/assignments']);

        // 7. shifts
        $noShift = [];
        foreach ($dayRows as $d) {
            if ($d->shift_id === null && ! in_array($d->status, self::OFF_STATUSES, true)) {
                $noShift[(int) $d->employee_id] = ($noShift[(int) $d->employee_id] ?? 0) + 1;
            }
        }
        $conditions[] = $this->condition('missing_shifts', 'Missing shifts', array_sum($noShift), 'employee-days', $employees, array_keys($noShift), $noShift,
            'Assign shifts or publish the roster', '/attendance/rosters', ['method' => 'GET', 'endpoint' => '/api/v1/attendance/roster-gaps']);

        // resolved vs unresolved employee-days (only shown together with the unresolved list)
        $unresolvedEmployees = [];
        foreach ($conditions as $c) {
            foreach ($c['sample_all'] as $row) {
                $unresolvedEmployees[$row['employee_uuid']]['employee'] ??= ['employee_uuid' => $row['employee_uuid'], 'employee_number' => $row['employee_number'], 'name' => $row['name']];
                $unresolvedEmployees[$row['employee_uuid']]['reasons'][] = ['condition' => $c['key'], 'count' => $row['count']];
            }
        }
        $resolvedDays = $dayRows->filter(fn ($d) => $d->finalization === 'locked' && $d->confidence === Confidence::CONFIRMED)->count();
        $ready = $totalExpected > 0 && collect($conditions)->every(fn ($c) => $c['count'] === 0);
        foreach ($conditions as &$c) {
            unset($c['sample_all']);
        }
        unset($c);

        return [
            'payroll_group' => $groupId ? DB::table('payroll_groups')->where('id', $groupId)->first(['id', 'name', 'code']) : null,
            'period' => ['from' => $from, 'to' => $to],
            'status' => $ready ? 'READY' : 'NOT READY',
            'ready' => $ready,
            'rule' => 'READY only when every condition below has a count of zero; there is no score.',
            'employees' => count($ids),
            'conditions' => $conditions,
            'employee_days' => ['expected' => $totalExpected, 'calculated' => count($dayRows), 'resolved' => $resolvedDays, 'unresolved' => max(0, $totalExpected - $resolvedDays),
                'resolved_percent' => $totalExpected > 0 ? round($resolvedDays / $totalExpected * 100, 1) : null],
            'unresolved_employees' => collect($unresolvedEmployees)->values()->take(100)->all(),
            'unresolved_employee_total' => count($unresolvedEmployees),
            'open_non_blocking_exceptions' => $nonBlocking,
            'confidence' => collect($dayRows)->countBy(fn ($d) => $d->confidence)->all() + ['confirmed' => 0, 'review_required' => 0, 'pending' => 0],
            'cutoff' => PayrollCutoff::state($today, PayrollCutoff::day($groupId)),
        ];
    }

    /** @param array<int,int> $affected @param array<int,int> $counts */
    private function condition(string $key, string $label, int $count, string $unit, $employees, array $affected, array $counts, string $actionLabel, string $href, array $api): array
    {
        $rows = [];
        foreach ($affected as $id) {
            $e = $employees[$id] ?? null;
            if ($e) {
                $rows[] = ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name, 'count' => (int) $counts[$id]];
            }
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['key' => $key, 'label' => $label, 'resolved' => $count === 0, 'count' => $count, 'unit' => $unit, 'affected_employees' => count($rows), 'sample' => array_slice($rows, 0, 10),
            'action' => ['label' => $actionLabel, 'kind' => 'navigate', 'href' => $href, 'api' => $api], 'sample_all' => $rows];
    }

    // ------------------------------------------------------------------------------------------------ output

    /**
     * Normalized per-employee output for payroll. Finalized (approved/locked) days only unless $includeOpen.
     *
     * @return array<string,mixed>
     */
    public function output(User $user, ?string $group, string $from, string $to, bool $includeOpen, int $page, int $perPage): array
    {
        $groupId = $this->groupId($group);
        $today = Ops::today();
        $base = $this->employees($user, $groupId, $from, $to);
        $total = (clone $base)->count();
        $employees = (clone $base)->select('e.id', 'e.uuid', 'e.employee_number', 'e.joined_on', 'e.separated_on', 'p.display_name')->orderBy('p.display_name')->orderBy('e.id')->forPage($page, $perPage)->get();
        $ids = $employees->pluck('id')->map(fn ($v) => (int) $v)->all();

        $days = DB::table('attendance_days')->where('tenant_id', Ops::tenantId())->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $ids ?: [0])
            ->select('employee_id', 'attendance_date', 'status', 'finalization', 'confidence', 'late_minutes', 'early_exit_minutes', 'approved_overtime_minutes', 'required_minutes')->orderBy('attendance_date')->get()->groupBy('employee_id');
        $leave = $ids ? app(LeaveDataProvider::class)->coverageForRange($ids, $from, $to) : [];

        $rows = [];
        foreach ($employees as $e) {
            $own = $days[$e->id] ?? collect();
            $counted = $includeOpen ? $own : $own->whereIn('finalization', ['approved', 'locked']);
            $excludedOpen = $includeOpen ? 0 : $own->count() - $counted->count();
            $start = max($from, $e->joined_on);
            $end = min($to, $today, $e->separated_on ?: $to);
            $expected = $start <= $end ? (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1 : 0;
            $unprocessed = max(0, $expected - $own->count());

            $present = $half = $absent = $weeklyOff = $holiday = 0;
            $leaveDays = $paidLeave = $unpaidLeave = 0.0;
            foreach ($counted as $d) {
                $coverage = $leave[$e->id.'|'.$d->attendance_date] ?? null;
                $fraction = $coverage ? $this->leaveFraction($coverage, (int) $d->required_minutes) : 0.0;
                switch (true) {
                    case in_array($d->status, AttendanceStatus::COUNTS_AS_PRESENT, true): $present++;
                        break;
                    case $d->status === AttendanceStatus::HALF_DAY: $half++;
                        break;
                    case $d->status === AttendanceStatus::WEEKLY_OFF: $weeklyOff++;
                        break;
                    case $d->status === AttendanceStatus::HOLIDAY: $holiday++;
                        break;
                    case $d->status === AttendanceStatus::ABSENT: $absent += max(0.0, 1 - $fraction);
                        break;
                    case $d->status === AttendanceStatus::LEAVE: $fraction = $coverage ? $fraction : 1.0;
                        break;
                }
                $coveredByLeave = ($coverage !== null && ! in_array($d->status, [AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, ...AttendanceStatus::COUNTS_AS_PRESENT], true)) || $d->status === AttendanceStatus::LEAVE;
                if ($coveredByLeave) {
                    $leaveDays += $fraction;
                    if (($coverage['paid'] ?? null) === true) {
                        $paidLeave += $fraction;
                    } elseif (($coverage['paid'] ?? null) === false) {
                        $unpaidLeave += $fraction;
                    }
                }
            }
            $payable = $present + 0.5 * $half + $weeklyOff + $holiday + $paidLeave;
            $codes = $counted->pluck('confidence')->all();
            $pendingParts = ($includeOpen ? 0 : $excludedOpen) + $unprocessed;
            $confidence = $pendingParts > 0 ? Confidence::worst([...$codes, Confidence::PENDING]) : Confidence::worst($codes ?: [Confidence::PENDING]);
            $rows[] = [
                'employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name,
                'payable_days' => round($payable, 2), 'present_days' => $present, 'absent_days' => round($absent, 2), 'half_days' => $half, 'weekly_off_days' => $weeklyOff, 'holiday_days' => $holiday,
                'approved_leave' => ['days' => round($leaveDays, 2), 'paid_days' => round($paidLeave, 2), 'unpaid_days' => round($unpaidLeave, 2), 'source' => 'leave_provider'],
                'unpaid_time' => ['absent_days' => round($absent, 2), 'unpaid_leave_days' => round($unpaidLeave, 2), 'note' => 'Placeholder: payroll decides what is unpaid.'],
                'approved_overtime_minutes' => (int) $counted->sum('approved_overtime_minutes'),
                'late_arrival_count' => $counted->where('late_minutes', '>', 0)->count(), 'early_departure_count' => $counted->where('early_exit_minutes', '>', 0)->count(),
                'confidence' => Confidence::describe($confidence),
                'days_counted' => $counted->count(), 'excluded_open_days' => $excludedOpen, 'unprocessed_days' => $unprocessed,
                'includes_open_days' => $includeOpen && $own->contains(fn ($d) => $d->finalization === 'open'),
                'complete' => $excludedOpen === 0 && $unprocessed === 0,
            ];
        }

        return [
            'payroll_group' => $groupId ? DB::table('payroll_groups')->where('id', $groupId)->first(['id', 'name', 'code']) : null,
            'period' => ['from' => $from, 'to' => $to],
            'data_status' => $includeOpen ? 'INCLUDES OPEN DATA - not final' : 'FINALIZED DATA ONLY',
            'include_open' => $includeOpen,
            'payable_days_rule' => 'present + on duty + remote + 0.5 x half days + weekly off + holiday + approved PAID leave. No salary is calculated.',
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** Fraction of a day covered by an approved leave coverage entry. */
    private function leaveFraction(array $coverage, int $requiredMinutes): float
    {
        return match ($coverage['portion'] ?? 'full_day') {
            'full_day' => 1.0,
            'first_half', 'second_half' => 0.5,
            default => $requiredMinutes > 0 ? min(1.0, ((int) ($coverage['minutes'] ?? 0)) / $requiredMinutes) : 0.0,
        };
    }
}
