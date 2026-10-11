<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveAccrualRule extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_accrual_rules';

    protected function casts(): array
    {
        return ['tenure_bands' => 'array', 'proration' => 'array'];
    }
}
