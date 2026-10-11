<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveCompOffClaim extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_comp_off_claims';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
