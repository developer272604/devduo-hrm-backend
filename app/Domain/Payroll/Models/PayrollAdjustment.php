<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollAdjustment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_adjustments';

    protected function casts(): array
    {
        return ['trace' => 'array', 'approved_at' => 'datetime', 'amount' => 'decimal:4'];
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
