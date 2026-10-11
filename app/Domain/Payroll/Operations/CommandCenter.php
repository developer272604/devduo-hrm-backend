<?php

namespace App\Domain\Payroll\Operations;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Readiness\ReadinessEngine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Payroll Command Center (spec 71): one read-only snapshot, every problem actionable. Money totals only for payroll.results.view holders. */
final class CommandCenter
{
    public function __construct(private ReadinessEngine $readiness) {}

    /** @return array<string,mixed> */
    public function snapshot(User $viewer): array
    {
        abort_unless(PayrollOps::any($viewer, PayrollOps::VIEW), 403, 'You do not have access to the payroll command center.');
        $tenant = PayrollOps::tenantId();
        $overview = $this->readiness->overview($viewer);
        $nav = fn (string $label, string $href) => ['label' => $label, 'kind' => 'navigate', 'href' => $href];
        $issues = DB::table('payroll_validation_issues')->where('tenant_id', $tenant)->whereIn('status', ['open', 'acknowledged']);
        $blocking = (clone $issues)->whereIn('severity', ['blocking', 'critical'])->count();
        $warnings = (clone $issues)->where('severity', 'warning')->count();
        $variance = (clone $issues)->where('code', 'like', 'variance%')->count();
        $runs = PayrollRun::with(['period', 'group'])->whereNotIn('status', ['closed', 'cancelled'])->orderByDesc('id')->limit(50)->get();
        $showMoney = $viewer->allows('payroll.results.view');
        $current = [];
        foreach ($runs as $run) {
            $row = ['uuid' => $run->uuid, 'group' => $run->group?->name, 'period' => $run->period?->label, 'run_type' => $run->run_type, 'status' => $run->status, 'stage' => (int) $run->stage, 'employees_total' => (int) $run->employees_total, 'employees_failed' => (int) $run->employees_failed];
            $row['control_totals'] = $showMoney ? RunTotals::forRun($run->id, $run->currency) : null;
            $current[] = $row;
        }
        $runIds = $runs->pluck('id')->all() ?: [0];

        return ['as_of' => PayrollOps::today(), 'readiness' => $overview,
            'processing' => ['active_runs' => $current, 'by_status' => $runs->countBy('status')->all(), 'failed_calculations' => (int) $runs->sum('employees_failed')],
            'blocking_errors' => ['count' => $blocking, 'action' => $nav('Open validations', '/payroll/validations')],
            'warnings' => ['count' => $warnings, 'action' => $nav('Open validations', '/payroll/validations')],
            'pending_approvals' => ['payroll' => DB::table('payroll_approvals')->where('tenant_id', $tenant)->where('status', 'pending')->selectRaw('subject_type, count(*) as c')->groupBy('subject_type')->pluck('c', 'subject_type')->all(),
                'compensation' => DB::table('employee_compensations')->where('tenant_id', $tenant)->where('status', 'pending')->count(), 'action' => $nav('Open approvals', '/my-work')],
            'variance_alerts' => ['count' => $variance, 'action' => $nav('Review variances', '/payroll/validations?code=variance')],
            'payments' => DB::table('payment_batches')->where('tenant_id', $tenant)->whereIn('run_id', $runIds)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            'locks' => ['locked' => DB::table('payroll_locks')->where('tenant_id', $tenant)->where('status', 'locked')->count(), 'reopen_requested' => DB::table('payroll_locks')->where('tenant_id', $tenant)->where('status', 'reopen_requested')->count()],
            'off_cycle_runs' => $runs->where('run_type', '!=', 'regular')->map(fn ($r) => ['uuid' => $r->uuid, 'run_type' => $r->run_type, 'status' => $r->status])->values()->all(),
            'money_visible' => $showMoney];
    }
}
