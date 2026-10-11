<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeEducation extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_education';

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }
}
