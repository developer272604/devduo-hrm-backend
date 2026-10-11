<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Dependent of a person. History is kept by end-dating (effective_to); benefits administration is out of scope. */
class EmployeeDependent extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_dependents';

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'benefit_eligibility' => 'array'];
    }
}
