<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollApproval extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_approvals';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
