<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Attendance\Operations\PayrollCutoff;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Payroll\PayrollContract;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Absence Command Center: one read-only snapshot of who is away, what waits for a decision, what is about to expire or go wrong,
 * and the payroll cutoff state. Everything is limited to the employees the viewer's data scope reaches; sensitive (medical)
 * leave counts only as "away" without a type unless the viewer holds leave.medical.view; no reasons anywhere.
 */
final class CommandCenter
{
    public const EXPIRY_WINDOW_DAYS = 30;

    public function __construct(private BalanceService $balances, private Confidence $confidence, private PayrollContract $payroll) {}

    /** @return array<string,mixed> */
    public function snapshot(User $viewer): array
    {
        $permissions = ['leave.reports.view', 'leave.manage', 'leave.team.view'];
        abort_unless(array_filter($permissions, fn ($p) => $viewer->allows($p)), 403, 'You do not have access to the absence command center.');
        $tenant = LeaveOps::tenantId();
        $today = LeaveOps::today();
        $ids = LeaveOps::employees($viewer, $permissions, true)->pluck('e.id')->map(fn ($v) => (int) $v)->all();
        $medical = LeaveOps::medical($viewer);
        $scoped = $ids ?: [0];

        // away today / next 7 days ------------------------------------------------------------------------------------
        $until = CarbonImmutable::parse($today)->addDays(6)->toDateString();
        $segments = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->join('leave_types as t', 't.id', '=', 's.leave_type_id')
            ->where('s.tenant_id', $tenant)->where('r.status', 'approved')->where('s.status', 'active')->whereBetween('s.leave_date', [$today, $until])->whereIn('s.employee_id', $scoped)
            ->get(['s.employee_id', 's.leave_date', 's.portion', 't.code', 't.is_sensitive']);
        $perDay = [];
        $byType = [];
        $awayToday = [];
        foreach ($segments as $s) {
            $date = substr((string) $s->leave_date, 0, 10);
            $perDay[$date][$s->employee_id] = true;
            if ($date === $today) {
                $awayToday[$s->employee_id] = true;
                $label = $s->is_sensitive && ! $medical ? 'Away' : $s->code;
                $byType[$label] = ($byType[$label] ?? 0) + 1;
            }
        }
        $upcoming = [];
        for ($i = 0; $i < 7; $i++) {
            $d = CarbonImmutable::parse($today)->addDays($i)->toDateString();
            $upcoming[] = ['date' => $d, 'away' => count($perDay[$d] ?? [])];
        }
        $names = $awayToday ? DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', array_keys($awayToday))->orderBy('p.display_name')->limit(10)->get(['e.uuid', 'p.display_name']) : collect();

        // approvals ----------------------------------------------------------------------------------------------------
        $escalate = (int) LeaveSettings::get('approvals.escalate_after_hours', 48);
        $pending = DB::table('leave_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $scoped)->get(['submitted_at', 'created_at']);
        $overdue = $pending->filter(fn ($r) => CarbonImmutable::parse($r->submitted_at ?? $r->created_at, 'UTC')->lessThan(now()->subHours($escalate)))->count();

        // balances ------------------------------------------------------------------------------------------------------
        $negative = 0;
        $expiringUnits = '0.0000';
        $expiringLots = 0;
        $horizon = CarbonImmutable::parse($today)->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString();
        foreach (LeaveAccount::query()->whereIn('employee_id', $scoped)->where('status', 'open')->get() as $account) {
            if (Units::isNegative($this->balances->balance($account, $today)->available)) {
                $negative++;
            }
            foreach ($this->balances->lots($account, $today) as $lot) {
                if ($lot['expires_on'] && $lot['expires_on'] <= $horizon) {
                    $expiringLots++;
                    $expiringUnits = Units::add($expiringUnits, $lot['remaining']);
                }
            }
        }

        // locks / payroll cutoff ----------------------------------------------------------------------------------------
        $monthStart = CarbonImmutable::parse($today)->startOfMonth()->subMonthNoOverflow();
        $period = [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()];
        $cutoff = $this->payroll->cutoff($period[0], $period[1], $today);
        $locked = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->where('start_date', '<=', $period[0])->where('end_date', '>=', $period[1])->exists();
        $unlockRequests = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->whereNotNull('unlock_requested_by')->count();

        $operator = $viewer->allows('leave.manage') || $viewer->allows('leave.accrual.run');
        $ops = null;
        if ($operator) {
            $since = now()->subDays(7);
            $ops = ['accrual_runs_needing_attention' => DB::table('leave_accrual_runs')->where('tenant_id', $tenant)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', $since)->count(),
                'imports_failed' => DB::table('leave_imports')->where('tenant_id', $tenant)->where('status', 'failed')->where('created_at', '>=', $since)->count(),
                'pending_adjustments' => DB::table('leave_adjustments')->where('tenant_id', $tenant)->where('status', 'pending')->count(),
                'open_quality_findings' => DB::table('data_quality_findings')->where('tenant_id', $tenant)->where('rule', 'like', 'leave\_%')->whereIn('status', ['open', 'acknowledged'])->count()];
        }

        return ['as_of' => $today, 'employees_in_scope' => count($ids),
            'away_today' => ['count' => count($awayToday), 'by_type' => $byType, 'people' => $names->map(fn ($n) => ['employee_uuid' => $n->uuid, 'name' => $n->display_name])->all(), 'next_7_days' => $upcoming],
            'approvals' => ['pending' => $pending->count(), 'overdue' => $overdue, 'escalate_after_hours' => $escalate],
            'balances' => ['negative_accounts' => $negative, 'expiring_within_days' => self::EXPIRY_WINDOW_DAYS, 'expiring_lots' => $expiringLots, 'expiring_units' => $expiringUnits],
            'payroll' => ['previous_period' => $period, 'cutoff' => $cutoff, 'period_locked' => $locked, 'unlock_requests_waiting' => $unlockRequests, 'legacy_attendance_cutoff_day' => PayrollCutoff::day()],
            'confidence' => $this->confidence->summary($ids), 'operations' => $ops];
    }
}
