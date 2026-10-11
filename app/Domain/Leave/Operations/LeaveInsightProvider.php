<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Insights\InsightProvider;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Payroll\PayrollContract;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave contribution to the Action Center. Deterministic, scoped to the viewer's data scope, neutral wording, every insight actionable.
 *
 *   critical         payroll cutoff passed while the previous period is neither locked nor free of pending leave
 *   action_required  balances below their allowed minimum; unlock requests waiting for a second person; failed accrual runs
 *   warning          requests waiting past the escalation window; leave expiring soon
 *   info             requests waiting inside the window
 */
final class LeaveInsightProvider implements InsightProvider
{
    private const VIEW = ['leave.approve', 'leave.manage', 'leave.reports.view', 'leave.team.view', 'leave.unlock', 'leave.accrual.run'];

    public function __construct(private BalanceService $balances, private PayrollContract $payroll) {}

    public function key(): string
    {
        return 'leave';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id || ! array_filter(self::VIEW, fn ($p) => $viewer->allows($p))) {
            return [];
        }
        $tenant = LeaveOps::tenantId();
        $today = LeaveOps::today();
        $people = LeaveOps::employees($viewer, self::VIEW, true)->select('e.id', 'e.uuid', 'p.display_name')->get()->keyBy('id');
        $ids = $people->keys()->map(fn ($v) => (int) $v)->all() ?: [0];
        $sample = fn (iterable $employeeIds) => collect($employeeIds)->unique()->filter(fn ($id) => isset($people[$id]))->take(5)->map(fn ($id) => ['employee_uuid' => $people[$id]->uuid, 'name' => $people[$id]->display_name])->values()->all();
        $nav = fn (string $label, string $href) => ['label' => $label, 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false];
        $out = [];
        $add = function (string $name, string $severity, int $count, string $title, string $description, array $actions, array $rows = []) use (&$out) {
            if ($count > 0) {
                $out[] = ['key' => 'leave.'.$name, 'domain' => 'leave', 'severity' => $severity, 'title' => $title, 'count' => $count, 'description' => $description, 'actions' => $actions, 'sample' => $rows];
            }
        };

        // requests waiting ---------------------------------------------------------------------------------------------
        $escalate = (int) LeaveSettings::get('approvals.escalate_after_hours', 48);
        $pending = DB::table('leave_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $ids)->get(['employee_id', 'submitted_at', 'created_at']);
        $late = $pending->filter(fn ($r) => CarbonImmutable::parse($r->submitted_at ?? $r->created_at, 'UTC')->lessThan(now()->subHours($escalate)));
        $add('requests_overdue', 'warning', $late->count(), $late->count().' leave '.($late->count() === 1 ? 'request has' : 'requests have').' waited longer than '.$escalate.' hours', 'Decide or reassign them so people can plan.', [$nav('Review leave approvals', '/leave/approvals')], $sample($late->pluck('employee_id')));
        $inside = $pending->count() - $late->count();
        $add('requests_pending', 'info', $inside, $inside.' leave '.($inside === 1 ? 'request awaits' : 'requests await').' a decision', 'Requests are waiting inside the approval window.', [$nav('Review leave approvals', '/leave/approvals')],
            $sample($pending->reject(fn ($r) => $late->contains($r))->pluck('employee_id')));

        // balances -----------------------------------------------------------------------------------------------------
        $below = [];
        $expiring = [];
        $horizon = CarbonImmutable::parse($today)->addDays(CommandCenter::EXPIRY_WINDOW_DAYS)->toDateString();
        foreach (LeaveAccount::query()->whereIn('employee_id', $ids)->where('status', 'open')->get() as $a) {
            $available = $this->balances->balance($a, $today)->available;
            if (Units::cmp($available, Units::neg(Units::normalize($a->getRawOriginal('negative_limit')))) < 0) {
                $below[$a->employee_id] = true;
            }
            foreach ($this->balances->lots($a, $today) as $lot) {
                if ($lot['expires_on'] && $lot['expires_on'] >= $today && $lot['expires_on'] <= $horizon) {
                    $expiring[$a->employee_id] = true;
                }
            }
        }
        $add('balance_below_limit', 'action_required', count($below), count($below).(count($below) === 1 ? ' employee has' : ' employees have').' a leave balance below the allowed minimum', 'Review the adjustments and requests behind these balances.', [$nav('Open leave reports', '/leave/reports/balances')], $sample(array_keys($below)));
        $add('expiring_soon', 'warning', count($expiring), count($expiring).(count($expiring) === 1 ? ' employee has' : ' employees have').' leave expiring in the next '.CommandCenter::EXPIRY_WINDOW_DAYS.' days', 'Encourage planning leave before it expires.', [$nav('See expiring balances', '/leave/reports/expiring')], $sample(array_keys($expiring)));

        // locks / payroll ------------------------------------------------------------------------------------------------
        if ($viewer->allows('leave.unlock')) {
            $waiting = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->whereNotNull('unlock_requested_by')->where('unlock_requested_by', '!=', $viewer->id)->count();
            $add('unlock_waiting', 'action_required', $waiting, $waiting.' leave unlock '.($waiting === 1 ? 'request needs' : 'requests need').' a second person', 'A different person with unlock permission must approve or decline.', [$nav('Open leave locks', '/leave/locks')]);
        }
        if ($viewer->allows('leave.manage') || $viewer->allows('leave.reports.view')) {
            $prev = CarbonImmutable::parse($today)->startOfMonth()->subMonthNoOverflow();
            $period = [$prev->toDateString(), $prev->endOfMonth()->toDateString()];
            $cutoff = $this->payroll->cutoff($period[0], $period[1], $today);
            if ($cutoff['configured'] && $cutoff['status'] === 'passed') {
                $locked = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->where('start_date', '<=', $period[0])->where('end_date', '>=', $period[1])->exists();
                $open = DB::table('leave_requests')->where('tenant_id', $tenant)->where('status', 'pending')->where('start_date', '<=', $period[1])->where('end_date', '>=', $period[0])->whereIn('employee_id', $ids)->count();
                $add('payroll_cutoff_risk', 'critical', $locked && $open === 0 ? 0 : max(1, $open), 'Leave for '.$period[0].' to '.$period[1].' is not closed after the payroll cutoff on '.$cutoff['cutoff_date'],
                    'Decide the pending requests and lock the period so payroll can rely on it.', [$nav('Open leave locks', '/leave/locks')]);
            }
        }
        if ($viewer->allows('leave.accrual.run') || $viewer->allows('leave.manage')) {
            $failed = DB::table('leave_accrual_runs')->where('tenant_id', $tenant)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subDays(7))->count();
            $add('accrual_failures', 'action_required', $failed, $failed.' accrual '.($failed === 1 ? 'run needs' : 'runs need').' attention', 'Some employees were not accrued. Open the run to retry.', [$nav('Open accrual runs', '/leave/accruals')]);
        }

        return $out;
    }
}
