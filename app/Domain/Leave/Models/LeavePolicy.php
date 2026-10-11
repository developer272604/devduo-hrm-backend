<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeavePolicy extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_policies';

    public function versions()
    {
        return $this->hasMany(LeavePolicyVersion::class, 'policy_id');
    }
}
