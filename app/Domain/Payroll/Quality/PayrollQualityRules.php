<?php

namespace App\Domain\Payroll\Quality;

use App\Domain\Access\Classification;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Quality\Finding;
use App\Domain\Quality\QualityRule;
use Illuminate\Support\Facades\DB;

/**
 * Payroll data-quality rules (spec 78). READ-ONLY: describe the problem and where to fix it. Never put amounts, bank or identifier values in details.
 * Payroll findings are hr_confidential (visible only with people.confidential.view).
 */
final class PayrollQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        $r = new self;

        return [
            self::rule('payroll_missing_group', 'Employees without a payroll group', fn (int $t) => $r->missingGroup($t)),
            self::rule('payroll_overlapping_compensation', 'Overlapping approved compensation', fn (int $t) => $r->overlapping($t)),
            self::rule('payroll_currency_conflict', 'Compensation currency differs from the payroll group', fn (int $t) => $r->currencyConflict($t)),
            self::rule('payroll_missing_component', 'Compensation lines using a component outside its effective dates', fn (int $t) => $r->missingComponent($t)),
            self::rule('payroll_formula_error', 'Salary component formulas that do not parse', fn (int $t) => $r->formulaError($t)),
            self::rule('payroll_invalid_bank', 'Missing or incomplete bank account for payroll employees', fn (int $t) => $r->invalidBank($t)),
            self::rule('payroll_duplicate_input', 'Duplicate payroll inputs', fn (int $t) => $r->duplicateInput($t)),
            self::rule('payroll_orphan_adjustment', 'Adjustments pointing at a finished or cancelled run', fn (int $t) => $r->orphanAdjustment($t)),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule
        {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string
            {
                return $this->key;
            }

            public function category(): string
            {
                return 'payroll';
            }

            public function label(): string
            {
                return $this->label;
            }

            public function scan(int $tenantId): iterable
            {
                return ($this->scanner)($tenantId);
            }
        };
    }

    private function find(?int $employeeId, string $fp, string $title, string $detail, string $severity, array $subject): Finding
    {
        return new Finding($employeeId, $fp, $title, $detail, $severity, $subject, [], Classification::HR_CONFIDENTIAL);
    }

    public function missingGroup(int $tenantId): iterable
    {
        if (! DB::table('payroll_groups')->where('tenant_id', $tenantId)->where('active', true)->exists()) {
            return;
        }
        $today = Ops::today();
        $ids = DB::table('employees as e')->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->whereIn('e.status', Ops::WORKING)
            ->whereNotExists(fn ($s) => $s->from('employee_policy_references as r')->whereColumn('r.employee_id', 'e.id')->where('r.kind', 'payroll_group')->where('r.effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today)))->orderBy('e.id')->pluck('e.id');
        foreach ($ids as $id) {
            yield $this->find((int) $id, 'emp:'.$id, 'No payroll group', 'This employee is not assigned to a payroll group today, so payroll will not include them.', 'action_required', ['employee', (int) $id]);
        }
    }

    public function overlapping(int $tenantId): iterable
    {
        $rows = DB::table('employee_compensations')->where('tenant_id', $tenantId)->where('status', 'approved')->orderBy('employee_id')->orderBy('effective_from')->get(['id', 'employee_id', 'effective_from', 'effective_to']);
        foreach ($rows->groupBy('employee_id') as $employeeId => $list) {
            $prev = null;
            foreach ($list as $row) {
                if ($prev && ($prev->effective_to === null || substr((string) $prev->effective_to, 0, 10) >= substr((string) $row->effective_from, 0, 10))) {
                    yield $this->find((int) $employeeId, 'comp:'.$prev->id.':'.$row->id, 'Overlapping compensation', 'Two approved compensation records cover the same dates. Close the earlier one.', 'critical', ['employee_compensation', (int) $row->id]);
                }
                $prev = $row;
            }
        }
    }

    public function currencyConflict(int $tenantId): iterable
    {
        $rows = DB::table('employee_compensations as c')->join('payroll_groups as g', 'g.id', '=', 'c.payroll_group_id')->where('c.tenant_id', $tenantId)->where('c.status', 'approved')
            ->whereNotNull('c.currency')->whereColumn('c.currency', '!=', 'g.currency')->get(['c.id', 'c.employee_id', 'c.currency', 'g.currency as group_currency']);
        foreach ($rows as $c) {
            yield $this->find((int) $c->employee_id, 'comp:'.$c->id, 'Compensation currency conflict', 'Compensation is in '.$c->currency.' but the payroll group pays in '.$c->group_currency.'.', 'action_required', ['employee_compensation', (int) $c->id]);
        }
    }

    public function missingComponent(int $tenantId): iterable
    {
        $rows = DB::table('compensation_lines as l')->join('employee_compensations as c', 'c.id', '=', 'l.compensation_id')->leftJoin('salary_components as s', 's.id', '=', 'l.component_id')
            ->where('l.tenant_id', $tenantId)->where('c.status', 'approved')
            ->where(fn ($w) => $w->whereNull('s.id')->orWhereColumn('s.effective_from', '>', 'c.effective_from')
                ->orWhere(fn ($x) => $x->whereNotNull('s.effective_to')->where(fn ($y) => $y->whereNull('c.effective_to')->orWhereColumn('s.effective_to', '<', 'c.effective_to'))))
            ->get(['l.id', 'c.employee_id', 'c.id as comp_id']);
        foreach ($rows as $l) {
            yield $this->find((int) $l->employee_id, 'line:'.$l->id, 'Component not valid for the compensation dates', 'A compensation line uses a salary component that is missing or not effective for the whole compensation period.', 'action_required', ['employee_compensation', (int) $l->comp_id]);
        }
    }

    public function formulaError(int $tenantId): iterable
    {
        $engine = app(FormulaEvaluator::class);
        foreach (DB::table('salary_components')->where('tenant_id', $tenantId)->whereNotNull('formula')->where('formula', '!=', '')->cursor() as $c) {
            try {
                $errors = $engine->validate($c->formula, $engine->references($c->formula));
            } catch (\Throwable $e) {
                $errors = [$e->getMessage()];
            }
            if ($errors) {
                yield $this->find(null, 'component:'.$c->id, 'Formula does not parse', 'Component '.$c->code.' v'.$c->version.' has a formula that cannot be evaluated.', 'action_required', ['salary_component', (int) $c->id]);
            }
        }
    }

    public function invalidBank(int $tenantId): iterable
    {
        if (! DB::table('payroll_groups')->where('tenant_id', $tenantId)->where('active', true)->exists()) {
            return;
        }
        $today = Ops::today();
        $rows = DB::table('employees as e')->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->whereIn('e.status', Ops::WORKING)
            ->whereExists(fn ($s) => $s->from('employee_policy_references as r')->whereColumn('r.employee_id', 'e.id')->where('r.kind', 'payroll_group')->where('r.effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today)))->orderBy('e.id')->get(['e.id', 'e.person_id']);
        $banks = DB::table('employee_bank_accounts')->where('tenant_id', $tenantId)->where('status', 'active')->get(['person_id', 'account_last4'])->groupBy('person_id');
        foreach ($rows as $e) {
            $own = $banks[$e->person_id] ?? collect();
            if ($own->isEmpty()) {
                yield $this->find((int) $e->id, 'bank:none:'.$e->id, 'No bank account', 'This payroll employee has no active bank account, so payment cannot be prepared.', 'action_required', ['employee', (int) $e->id]);
            } elseif ($own->contains(fn ($b) => empty($b->account_last4))) {
                yield $this->find((int) $e->id, 'bank:incomplete:'.$e->id, 'Incomplete bank account', 'An active bank account has no usable account number.', 'warning', ['employee', (int) $e->id]);
            }
        }
    }

    public function duplicateInput(int $tenantId): iterable
    {
        $rows = DB::table('payroll_inputs')->where('tenant_id', $tenantId)->whereNotIn('status', ['void', 'rejected'])->whereNotNull('period_id')
            ->selectRaw('employee_id, period_id, source, kind, component_code, quantity, amount, count(*) as c, min(id) as first_id')
            ->groupBy('employee_id', 'period_id', 'source', 'kind', 'component_code', 'quantity', 'amount')->havingRaw('count(*) > 1')->get();
        foreach ($rows as $r) {
            yield $this->find((int) $r->employee_id, 'dup:'.$r->employee_id.':'.$r->period_id.':'.$r->source.':'.$r->kind.':'.$r->component_code, 'Duplicate payroll input', $r->c.' identical '.$r->source.' inputs exist for the same period; the pay could be counted twice.', 'action_required', ['payroll_input', (int) $r->first_id]);
        }
    }

    public function orphanAdjustment(int $tenantId): iterable
    {
        $rows = DB::table('payroll_adjustments as a')->join('payroll_runs as r', 'r.id', '=', 'a.run_id')->where('a.tenant_id', $tenantId)->whereIn('a.status', ['pending', 'approved'])->whereIn('r.status', ['cancelled', 'closed'])->get(['a.id', 'a.employee_id']);
        foreach ($rows as $a) {
            yield $this->find((int) $a->employee_id, 'adj:'.$a->id, 'Orphan adjustment', 'An adjustment is still open but its payroll run is closed or cancelled. Re-assign or cancel it.', 'warning', ['payroll_adjustment', (int) $a->id]);
        }
    }
}
