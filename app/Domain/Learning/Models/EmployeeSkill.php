<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\TenantRecord;

class EmployeeSkill extends TenantRecord
{
    protected $table = 'employee_skills';

    protected function casts(): array
    {
        return ['validated_at' => 'datetime', 'last_used' => 'date:Y-m-d'];
    }
}
