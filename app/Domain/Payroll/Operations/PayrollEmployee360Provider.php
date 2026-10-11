<?php

namespace App\Domain\Payroll\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Payroll\Security\SensitiveAccess;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Domain\Payroll\Models\EmployeeCompensation;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Employee 360 payroll panel. Every section is permission + data-scope gated on its own and omitted when empty.
 *   compensation          current approved lines; figures revealed only with payroll.compensation.reveal AND a stated purpose (logged), else masked
 *   compensation_history  effective-dated compensation records (dates, status, source; no figures)
 *   payroll_status        latest run/period state for the employee (no figures)
 *   payslips              payslip versions (period, version, status; no figures)
 *   benefits_loans        benefit enrolments and loan/advance status counts (no figures)
 */
final class PayrollEmployee360Provider implements Employee360Provider
{
    private const MASK = '••••••';

    public function key(): string
    {
        return 'payroll';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        if (! in_array('payroll', $viewer->tenant->settings['modules'] ?? [], true)) {
            return [];
        }
        $scope = app(DataScope::class);
        $can = fn (array $permissions) => (bool) array_filter($permissions, fn ($p) => $viewer->allows($p) && $scope->canAccess($viewer, $employee, $p));
        $today = TenantClock::today($employee->timezone);
        $sections = [];

        if ($can(['payroll.compensation.view'])) {
            $record = EmployeeCompensation::where('employee_id', $employee->id)->where('status', 'approved')->where('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->with(['lines.component', 'group'])->first();
            if ($record) {
                $reveal = SensitiveAccess::canSeeFigures($viewer, 'payroll.compensation.reveal');
                SensitiveAccess::log($viewer, $employee->id, 'compensation', $reveal ? 'reveal' : 'view', $reveal, 'employee360', $record->uuid);
                $currency = $record->group?->currency ?? $record->currency;
                $sections[] = ['key' => 'compensation', 'title' => 'Current approved compensation', 'source' => 'payroll', 'order' => 50, 'masked' => ! $reveal,
                    'purpose_required' => ! $reveal && $viewer->allows('payroll.compensation.reveal') && SensitiveAccess::purposeRequired(),
                    'items' => $record->lines->map(fn ($line) => ['key' => $line->uuid, 'label' => $line->component->name, 'value' => $reveal ? $currency.' '.$line->amount : self::MASK,
                        'detail' => ($record->group?->frequency ?? $record->pay_frequency).' · component v'.$line->component->version, 'date' => $record->effective_from, 'href' => '/payroll', 'source' => 'payroll'])->all()];
            }
            $history = EmployeeCompensation::where('employee_id', $employee->id)->orderByDesc('effective_from')->limit(20)->get();
            if ($history->isNotEmpty()) {
                $sections[] = ['key' => 'compensation_history', 'title' => 'Compensation history', 'source' => 'payroll', 'order' => 51,
                    'items' => $history->map(fn ($c) => ['key' => $c->uuid, 'label' => ucfirst($c->status), 'detail' => 'Effective '.substr((string) $c->getRawOriginal('effective_from'), 0, 10).' to '.($c->getRawOriginal('effective_to') ? substr((string) $c->getRawOriginal('effective_to'), 0, 10) : 'open').' · '.($c->source ?? 'manual'),
                        'date' => $c->effective_from, 'href' => '/payroll', 'source' => 'payroll'])->all()];
            }
        }

        if ($can(['payroll.view', 'payroll.results.view'])) {
            $re = DB::table('payroll_run_employees as pre')->join('payroll_runs as r', 'r.id', '=', 'pre.run_id')->join('payroll_periods as p', 'p.id', '=', 'r.period_id')
                ->where('pre.employee_id', $employee->id)->where('r.status', '!=', 'cancelled')->orderByDesc('p.period_end')->orderByDesc('r.id')->first(['pre.status as employee_status', 'r.status as run_status', 'r.run_type', 'p.label', 'pre.exclusion_reason']);
            if ($re) {
                $sections[] = ['key' => 'payroll_status', 'title' => 'Payroll status', 'source' => 'payroll', 'order' => 52,
                    'items' => [['key' => 'latest', 'label' => $re->label.' ('.$re->run_type.')', 'value' => $re->employee_status, 'detail' => 'Run '.$re->run_status.($re->exclusion_reason ? ' · '.$re->exclusion_reason : ''), 'href' => '/payroll/runs', 'source' => 'payroll']]];
            }
        }

        if ($can(['payroll.payslips.manage', 'payroll.results.view'])) {
            $slips = DB::table('payslips as s')->join('payroll_periods as p', 'p.id', '=', 's.period_id')->where('s.employee_id', $employee->id)->orderByDesc('p.period_end')->orderByDesc('s.version')->limit(12)->get(['s.uuid', 's.version', 's.status', 'p.label']);
            if ($slips->isNotEmpty()) {
                $sections[] = ['key' => 'payslips', 'title' => 'Payslips', 'source' => 'payroll', 'order' => 53,
                    'items' => $slips->map(fn ($s) => ['key' => $s->uuid, 'label' => $s->label, 'value' => 'v'.$s->version, 'detail' => $s->status, 'href' => '/payroll/payslips', 'source' => 'payroll'])->all()];
            }
        }

        if ($can(['payroll.benefits.manage', 'payroll.loans.manage', 'payroll.compensation.view'])) {
            $items = [];
            foreach (DB::table('employee_benefit_enrollments as en')->join('benefit_plans as b', 'b.id', '=', 'en.plan_id')->where('en.employee_id', $employee->id)->limit(20)->get(['en.uuid', 'en.status', 'b.name']) as $b) {
                $items[] = ['key' => $b->uuid, 'label' => $b->name, 'value' => $b->status, 'detail' => 'Benefit', 'href' => '/benefits', 'source' => 'payroll'];
            }
            foreach (DB::table('employee_loans')->where('employee_id', $employee->id)->limit(20)->get(['uuid', 'status', 'installments']) as $l) {
                $items[] = ['key' => $l->uuid, 'label' => 'Loan', 'value' => $l->status, 'detail' => $l->installments.' installments', 'href' => '/payroll/loans', 'source' => 'payroll'];
            }
            foreach (DB::table('employee_advances')->where('employee_id', $employee->id)->limit(20)->get(['uuid', 'status']) as $a) {
                $items[] = ['key' => $a->uuid, 'label' => 'Advance', 'value' => $a->status, 'detail' => 'Advance', 'href' => '/payroll/loans', 'source' => 'payroll'];
            }
            if ($items) {
                $sections[] = ['key' => 'benefits_loans', 'title' => 'Benefits, loans and advances', 'source' => 'payroll', 'order' => 54, 'items' => $items];
            }
        }

        return $sections;
    }
}
