<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class EmployeeCert extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_employee_certs';

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d', 'last_reminded_at' => 'datetime'];
    }
}
