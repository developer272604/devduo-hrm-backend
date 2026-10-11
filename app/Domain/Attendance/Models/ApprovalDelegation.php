<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Temporary delegation of attendance approvals from one user to another. */
class ApprovalDelegation extends TenantRecord
{
    use HasPublicId;

    protected $table = 'approval_delegations';

    protected function casts(): array
    {
        return ['kinds' => 'array', 'active' => 'boolean'];
    }
}
