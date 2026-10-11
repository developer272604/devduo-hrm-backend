<?php

namespace Tests\Concerns;

use App\Domain\People\Models\Employee;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;

/**
 * Payroll test fixtures (use together with PeopleFixtures). Builds the minimum aggregate chain through the real models:
 *   payrollGroup() -> payrollPeriod($group) -> payrollRun($group, $period) -> runEmployee($run, $employee)
 * Tenant context must be set ($this->bootTenant() does it).
 */
trait PayrollFixtures
{
    protected function payrollGroup(array $o = []): PayrollGroup
    {
        return PayrollGroup::create($o + ['name' => 'India monthly', 'code' => 'IN_MONTHLY', 'legal_entity_id' => $this->org['legal_entity'][0], 'country' => 'IN', 'currency' => 'INR',
            'timezone' => 'Asia/Kolkata', 'frequency' => 'monthly', 'active' => true]);
    }

    protected function payrollPeriod(PayrollGroup $group, string $start = '2026-09-01', string $end = '2026-09-30', array $o = []): PayrollPeriod
    {
        return PayrollPeriod::create($o + ['payroll_group_id' => $group->id, 'label' => substr($start, 0, 7), 'period_start' => $start, 'period_end' => $end, 'state' => 'input_open']);
    }

    protected function payrollRun(PayrollGroup $group, PayrollPeriod $period, array $o = []): PayrollRun
    {
        return PayrollRun::create($o + ['payroll_group_id' => $group->id, 'period_id' => $period->id, 'run_number' => 1, 'run_type' => 'regular', 'status' => 'review',
            'currency' => $group->currency, 'created_by' => $this->admin->id]);
    }

    protected function runEmployee(PayrollRun $run, Employee $employee): PayrollRunEmployee
    {
        return PayrollRunEmployee::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'payroll_group_id' => $run->payroll_group_id]);
    }

    /** @return array<int,array<string,mixed>> */
    protected function sampleComponents(): array
    {
        return [
            ['component_code' => 'BASE', 'name' => 'Base pay', 'kind' => 'earning', 'amount' => '50000.00', 'formula' => null, 'origin' => 'compensation'],
            ['component_code' => 'HRA', 'name' => 'Housing allowance', 'kind' => 'earning', 'amount' => '20000.00', 'origin' => 'compensation'],
            ['component_code' => 'TAXC', 'name' => 'Income tax', 'kind' => 'tax', 'amount' => '4500.50', 'origin' => 'statutory'],
            ['component_code' => 'EEPF', 'name' => 'Employee pension', 'kind' => 'employee_contribution', 'amount' => '1800.00', 'origin' => 'statutory'],
            ['component_code' => 'ERPF', 'name' => 'Employer pension', 'kind' => 'employer_contribution', 'amount' => '1800.00', 'origin' => 'statutory'],
            ['component_code' => 'TRVL', 'name' => 'Travel reimbursement', 'kind' => 'reimbursement', 'amount' => '1200.00', 'origin' => 'input'],
            ['component_code' => 'LOAN', 'name' => 'Loan recovery', 'kind' => 'deduction', 'amount' => '2000.00', 'origin' => 'loan'],
        ];
    }
}
