<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveOptionalHolidayPool extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_optional_holiday_pools';

    protected function casts(): array
    {
        return ['holidays' => 'array', 'applicability' => 'array'];
    }
}
