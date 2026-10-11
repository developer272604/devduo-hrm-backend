<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LoanInstallment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'loan_installments';

    protected function casts(): array
    {
        return ['principal' => 'decimal:4', 'interest' => 'decimal:4', 'amount' => 'decimal:4'];
    }

    public function loan()
    {
        return $this->belongsTo(EmployeeLoan::class, 'loan_id');
    }
}
