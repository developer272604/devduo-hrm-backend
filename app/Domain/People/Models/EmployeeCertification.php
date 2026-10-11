<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeCertification extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_certifications';

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }
}
