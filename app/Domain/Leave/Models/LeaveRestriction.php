<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveRestriction extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_restrictions';

    protected function casts(): array
    {
        return ['params' => 'array'];
    }
}
