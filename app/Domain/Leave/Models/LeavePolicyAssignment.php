<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeavePolicyAssignment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_policy_assignments';
}
