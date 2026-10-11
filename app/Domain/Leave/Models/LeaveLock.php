<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveLock extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_locks';

    protected function casts(): array
    {
        return ['locked_at' => 'datetime', 'unlocked_at' => 'datetime'];
    }
}
