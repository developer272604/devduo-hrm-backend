<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveCycle extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_cycles';

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
