<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Insights\InsightProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance contribution to the Action Center (People "Needs attention"). Deterministic and explainable:
 *
 *   critical         attendance NOT finalized once the payroll cutoff has been reached (the only critical condition)
 *   action_required  missing punches blocking finalization; unfinalized attendance inside the pre-cutoff window
 *   warning          no shift tomorrow, devices not synced, processing failures, repeated lateness, regularizations waiting past the approval window
 *   info             regularizations/overtime waiting within the window, approved overtime recorded
 *
 * No scores, no ranking of people, neutral wording. Every insight carries at least one action.
 */
final class AttendanceInsightProvider implements InsightProvider
{
    /** Late on at least this many days of the current month is reported as "repeated lateness" (a fact, not a verdict). */
    public const REPEATED_LATE_DAYS = 3;

    private const VIEW = ['attendance.manage', 'attendance.process', 'attendance.reports.view', 'attendance.team.view', 'attendance.approve'];

    public function __construct(private Coverage $coverage) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id || ! array_filter(self::VIEW, fn ($p) => $viewer->allows($p))) {
            return [];
        }
        $tenant = Ops::tenantId();
        $today = Ops::today();
        $operator = $viewer->allows('attendance.manage') || $viewer->allows('attendance.process');
        $people = Ops::employees($viewer, self::VIEW, true)->select('e.id', 'e.uuid', 'p.display_name')->get()->keyBy('id');
        $ids = $people->keys()->map(fn ($v) => (int) $v)->all();
        $sample = fn (iterable $employeeIds) => collect($employeeIds)->unique()->filter(fn ($id) => isset($people[$id]))->take(5)->map(fn ($id) => ['employee_uuid' => $people[$id]->uuid, 'name' => $people[$id]->display_name])->values()->all();
        $out = [];
        $add = function (string $name, string $severity, int $count, string $title, string $description, array $actions, array $sampleRows = []) use (&$out) {
            if ($count > 0) {
                $out[] = ['key' => 'attendance.'.$name, 'domain' => 'attendance', 'severity' => $severity, 'title' => $title, 'count' => $count, 'description' => $description, 'actions' => $actions, 'sample' => $sampleRows];
            }
        };
        $nav = fn (string $label, string $href) => ['label' => $label, 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false];

        // payroll cutoff risk -------------------------------------------------------------------------------------
        $cutoff = PayrollCutoff::state($today, PayrollCutoff::day());
        if ($cutoff['configured'] && in_array($cutoff['status'], ['passed', 'at_risk_window'], true)) {
            $open = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$cutoff['period_from'], $cutoff['period_to']])->where('finalization', 'open')->whereIn('employee_id', $ids ?: [0])->pluck('employee_id')->unique()->values();
            $passed = $cutoff['status'] === 'passed';
            $add('payroll_cutoff_risk', $passed ? 'critical' : 'action_required', $open->count(),
                $open->count().($open->count() === 1 ? ' employee has' : ' employees have').' attendance not finalized '.($passed ? 'after the payroll cutoff on ' : 'before the payroll cutoff on ').$cutoff['cutoff_date'],
                'Attendance for '.$cutoff['period_from'].' to '.$cutoff['period_to'].' must be approved or locked before payroll can use it.',
                [$nav('Open payroll readiness', '/attendance/time-office/payroll-readiness?from='.$cutoff['period_from'].'&to='.$cutoff['period_to'])], $sample($open));
        }

        // missing punches blocking finalization ----------------------------------------------------------------------
        $missing = DB::table('attendance_days')->where('tenant_id', $tenant)->where('attendance_date', '<=', $today)->where('missing_punch', true)->where('finalization', 'open')->whereIn('employee_id', $ids ?: [0])->get(['employee_id']);
        $add('missing_punches', 'action_required', $missing->count(), $missing->count().' missing '.($missing->count() === 1 ? 'punch needs' : 'punches need').' resolution',
            'Open days with a missing punch cannot be finalized until the punch is added or the day is regularized.', [$nav('Resolve missing punches', '/attendance/time-office/missing-punches')], $sample($missing->pluck('employee_id')));

        // regularizations / overtime waiting ---------------------------------------------------------------------------
        $window = (int) AttendanceSettings::get('cutoffs.manager_approval_days', 2);
        $regs = DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $ids ?: [0])->get(['employee_id', 'created_at']);
        $overdue = $regs->contains(fn ($r) => CarbonImmutable::parse($r->created_at)->lessThan(now()->subDays($window)));
        $add('regularizations_pending', $overdue ? 'warning' : 'info', $regs->count(), $regs->count().($regs->count() === 1 ? ' regularization awaits' : ' regularizations await').' approval',
            $overdue ? 'At least one request has waited longer than the '.$window.'-day approval window.' : 'Requests are waiting inside the approval window.', [$nav('Review regularizations', '/attendance/time-office/approvals?kind=regularization')], $sample($regs->pluck('employee_id')));
        $ot = DB::table('overtime_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $ids ?: [0])->get(['employee_id']);
        $add('overtime_pending', 'info', $ot->count(), $ot->count().' overtime '.($ot->count() === 1 ? 'request awaits' : 'requests await').' approval', 'Overtime counts toward payroll only after approval.', [$nav('Review overtime', '/attendance/time-office/approvals?kind=overtime')], $sample($ot->pluck('employee_id')));

        // no shift tomorrow ------------------------------------------------------------------------------------------
        $tomorrow = CarbonImmutable::parse($today)->addDay()->toDateString();
        $unassigned = array_keys(array_filter($this->coverage->shiftStates($ids, $tomorrow), fn ($s) => $s['state'] === 'unassigned'));
        $add('no_shift_tomorrow', 'warning', count($unassigned), count($unassigned).($this->one($unassigned) ? ' employee has' : ' employees have').' no shift tomorrow', 'Nothing assigns a shift on '.$tomorrow.'. Assign a shift or publish the roster.',
            [$nav('Assign shifts', '/attendance/rosters?date='.$tomorrow)], $sample($unassigned));

        if ($operator) {
            $devices = DB::table('attendance_devices')->where('tenant_id', $tenant)->whereIn('status', ['active', 'error'])->get(['status', 'last_sync_at', 'sync_interval_minutes'])
                ->filter(fn ($d) => $d->status === 'error' || ! $d->last_sync_at || CarbonImmutable::parse($d->last_sync_at, 'UTC')->lessThan(now()->subMinutes(2 * max(1, (int) $d->sync_interval_minutes))));
            $add('devices_not_synced', 'warning', $devices->count(), $devices->count().($devices->count() === 1 ? ' device has' : ' devices have').' not synced', 'Punches from these devices may be missing until they sync again.', [$nav('Check devices', '/attendance/devices')]);
            $failed = DB::table('attendance_processing_runs')->where('tenant_id', $tenant)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subDay())->count();
            $add('processing_failures', 'warning', $failed, $failed.($failed === 1 ? ' processing run' : ' processing runs').' did not complete in the last 24 hours', 'Some employee-days may be out of date. Open processing to retry.', [$nav('Open processing', '/attendance/time-office/processing')]);
        }

        // repeated lateness (facts, warning at most) ---------------------------------------------------------------------
        $monthStart = CarbonImmutable::parse($today)->startOfMonth()->toDateString();
        $late = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$monthStart, $today])->where('late_minutes', '>', 0)->whereIn('employee_id', $ids ?: [0])
            ->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->havingRaw('count(*) >= ?', [self::REPEATED_LATE_DAYS])->pluck('c', 'employee_id');
        $add('repeated_lateness', 'warning', $late->count(), $late->count().($late->count() === 1 ? ' employee was' : ' employees were').' recorded late on '.self::REPEATED_LATE_DAYS.' or more days this month', 'Review the arrival times and shift assignments before drawing conclusions.',
            [$nav('Review late arrivals', '/attendance/time-office/late?from='.$monthStart.'&to='.$today)], $sample($late->keys()));

        // approved overtime recorded ----------------------------------------------------------------------------------------
        $approved = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$monthStart, $today])->where('approved_overtime_minutes', '>', 0)->where('finalization', 'open')->whereIn('employee_id', $ids ?: [0])->pluck('employee_id')->unique();
        $add('overtime_approved', 'info', $approved->count(), 'Approved overtime is recorded for '.$approved->count().($approved->count() === 1 ? ' employee' : ' employees').' this month', 'It will flow to payroll once the days are finalized.',
            [$nav('Review overtime report', '/attendance/reports/overtime?from='.$monthStart.'&to='.$today)], $sample($approved));

        return $out;
    }

    private function one(array $items): bool
    {
        return count($items) === 1;
    }
}
