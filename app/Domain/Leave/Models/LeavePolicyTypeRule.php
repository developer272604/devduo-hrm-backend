<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeavePolicyTypeRule extends TenantRecord
{
    protected $table = 'leave_policy_type_rules';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
