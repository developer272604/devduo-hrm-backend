<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveTypeMapping extends TenantRecord
{
    protected $table = 'leave_type_mappings';
}
