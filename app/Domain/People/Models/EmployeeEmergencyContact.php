<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeEmergencyContact extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_emergency_contacts';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
