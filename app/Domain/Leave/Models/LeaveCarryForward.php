<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveCarryForward extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_carry_forwards';

    protected function casts(): array
    {
        return ['calculation' => 'array'];
    }
}
