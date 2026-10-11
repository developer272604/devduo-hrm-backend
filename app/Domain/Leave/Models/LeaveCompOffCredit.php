<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveCompOffCredit extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_comp_off_credits';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
