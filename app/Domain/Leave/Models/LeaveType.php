<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveType extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_types';

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
