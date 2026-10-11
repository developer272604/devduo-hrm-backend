<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeavePolicyVersion extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_policy_versions';

    protected function casts(): array
    {
        return ['rules' => 'array', 'published_at' => 'datetime'];
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'policy_id');
    }

    public function typeRules()
    {
        return $this->hasMany(LeavePolicyTypeRule::class, 'policy_version_id');
    }
}
