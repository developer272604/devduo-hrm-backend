<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class AccountingMapping extends TenantRecord
{
    use HasPublicId;

    protected $table = 'accounting_mappings';

    protected function casts(): array
    {
        return ['dimensions' => 'array', 'allocation' => 'array', 'active' => 'boolean'];
    }
}
