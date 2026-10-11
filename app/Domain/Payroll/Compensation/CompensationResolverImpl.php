<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Models\EmployeeCompensation;

/** As-of resolution of APPROVED compensation (never pending, rejected, cancelled or superseded rows). Tenant isolation comes from the model scope. */
final class CompensationResolverImpl implements CompensationResolver
{
    public function current(int $employeeId, string $date): ?array
    {
        $r = EmployeeCompensation::where('employee_id', $employeeId)->where('status', 'approved')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->with('lines.component')->first();

        return $r ? $this->shape($r) : null;
    }

    public function between(int $employeeId, string $from, string $to): array
    {
        return EmployeeCompensation::where('employee_id', $employeeId)->where('status', 'approved')->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->orderBy('effective_from')->with('lines.component')->get()->map(fn ($r) => $this->shape($r))->all();
    }

    private function shape(EmployeeCompensation $r): array
    {
        $d = fn ($v) => $v ? substr((string) $v, 0, 10) : null;

        return ['compensation_id' => $r->id, 'structure_id' => $r->structure_id, 'payroll_group_id' => $r->payroll_group_id, 'currency' => $r->currency, 'pay_frequency' => $r->pay_frequency,
            'effective_from' => $d($r->effective_from), 'effective_to' => $d($r->effective_to), 'base_pay' => $r->base_pay, 'gross_reference' => $r->gross_reference,
            'annual_compensation' => $r->annual_compensation, 'hourly_rate' => $r->hourly_rate, 'daily_rate' => $r->daily_rate,
            'lines' => $r->lines->sortBy('sequence')->values()->map(fn ($l) => ['component_id' => $l->component_id, 'component_code' => $l->component->code, 'component_version' => $l->component->version,
                'kind' => $l->component->kind, 'name' => $l->component->name, 'amount' => $l->amount, 'formula' => $l->basis['formula'] ?? null, 'formula_version' => $l->component->version,
                'deferred' => (bool) ($l->basis['deferred'] ?? false), 'sequence' => $l->sequence])->all()];
    }
}
