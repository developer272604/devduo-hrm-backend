<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Previous employment. last_salary holds a Crypt-encrypted string; never serialize it without a FieldAccess check. */
class EmployeeExperience extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_experience';

    protected $hidden = ['last_salary'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }
}
