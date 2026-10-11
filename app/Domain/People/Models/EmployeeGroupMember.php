<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class EmployeeGroupMember extends TenantRecord
{
    protected $table = 'employee_group_members';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }
}
