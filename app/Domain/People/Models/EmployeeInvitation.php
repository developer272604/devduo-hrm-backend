<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeInvitation extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_invitations';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
