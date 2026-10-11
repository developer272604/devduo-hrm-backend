<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class EmployeeCompensation extends TenantRecord
{
    use HasPublicId;
    protected $table = 'employee_compensations';
    protected function casts(): array
    {
        return ['base_pay' => 'encrypted', 'gross_reference' => 'encrypted', 'annual_compensation' => 'encrypted', 'hourly_rate' => 'encrypted', 'daily_rate' => 'encrypted', 'meta' => 'array', 'approved_at' => 'datetime'];
    }

    public function structure() { return $this->belongsTo(SalaryStructure::class, 'structure_id'); }
    public function lines() { return $this->hasMany(CompensationLine::class, 'compensation_id'); }
    public function employee() { return $this->belongsTo(\App\Domain\People\Models\Employee::class); }
    public function group() { return $this->belongsTo(PayrollGroup::class, 'payroll_group_id'); }
}
