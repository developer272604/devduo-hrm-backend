<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class EmployeeAdvance extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_advances';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'recovered' => 'decimal:4'];
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
