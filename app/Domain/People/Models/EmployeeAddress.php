<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeAddress extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_addresses';

    public const TYPES = ['current', 'permanent', 'mailing', 'other'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'is_primary' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }
}
