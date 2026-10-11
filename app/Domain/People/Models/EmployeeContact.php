<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Email / phone record of a person. Exactly one current primary per kind (enforced by ContactService). */
class EmployeeContact extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_contacts';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }
}
