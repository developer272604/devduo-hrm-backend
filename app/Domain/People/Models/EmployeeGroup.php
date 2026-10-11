<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Named set of employees, used for custom-group data scope and (later) time-policy assignment. */
class EmployeeGroup extends TenantRecord
{
    protected $table = 'employee_groups';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function members()
    {
        return $this->hasMany(EmployeeGroupMember::class, 'group_id');
    }
}
