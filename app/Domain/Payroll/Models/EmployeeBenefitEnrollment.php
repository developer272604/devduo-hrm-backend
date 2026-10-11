<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class EmployeeBenefitEnrollment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_benefit_enrollments';

    protected function casts(): array
    {
        return ['employee_contribution' => 'encrypted', 'employer_contribution' => 'encrypted', 'dependents' => 'array'];
    }

    public function plan()
    {
        return $this->belongsTo(BenefitPlan::class, 'plan_id');
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
