<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeBankAccount extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_bank_accounts';

    protected $hidden = ['details_encrypted'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }
}
