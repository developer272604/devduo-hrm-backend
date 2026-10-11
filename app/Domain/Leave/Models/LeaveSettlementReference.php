<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveSettlementReference extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_settlement_references';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
