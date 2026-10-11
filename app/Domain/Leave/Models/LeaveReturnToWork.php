<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveReturnToWork extends TenantRecord
{
    protected $table = 'leave_return_to_work';

    protected function casts(): array
    {
        return ['checklist' => 'array'];
    }
}
