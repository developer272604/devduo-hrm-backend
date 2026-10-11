<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Results\ResultDraft;

/**
 * The pure per-employee payroll calculation. Owner: workstream B (binds it in RunBoot). It NEVER writes: the run engine passes the returned
 * ResultDraft to Contracts\ResultStore. Simulators, impact previews, revision previews and the explainer (workstream D) call the very same
 * method with hypothetical inputs, so a simulation can never diverge from live payroll and can never mutate it.
 *
 * $request: {employee_id, group_id, period_start, period_end, currency, compensation (CompensationResolver shape), pack_version_id?,
 *            inputs[] (PayrollInputLine arrays + stored payroll_inputs), adjustments[], loan_installments[], benefit_enrollments[],
 *            overrides? (simulation only: compensation / formula / pack rules / lop days / bonus), run_type, ytd? }
 * Same request => same draft (deterministic, decimal-safe, explicit rounding). Errors for ONE employee are returned in the draft
 * (errors[]) rather than thrown, so one failure never destroys a run.
 */
interface EmployeeCalculator
{
    /** @param array<string,mixed> $request */
    public function calculate(array $request): ResultDraft;
}
