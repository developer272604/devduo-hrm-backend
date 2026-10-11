<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollExport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_exports';

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime'];
    }
}
