<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class EmployeeLoan extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_loans';

    protected function casts(): array
    {
        return ['interest' => 'array', 'approved_at' => 'datetime', 'principal' => 'decimal:4'];
    }

    public function installments()
    {
        return $this->hasMany(LoanInstallment::class, 'loan_id');
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
