<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveTransfer extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_transfers';

    protected function casts(): array
    {
        return ['plan' => 'array', 'result' => 'array'];
    }
}
