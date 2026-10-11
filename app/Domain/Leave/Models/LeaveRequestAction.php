<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveRequestAction extends TenantRecord
{
    protected $table = 'leave_request_actions';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
