<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** On-duty / remote-work request. starts_at/ends_at are UTC strings. */
class OnDutyRequest extends TenantRecord
{
    use HasPublicId;

    protected $table = 'on_duty_requests';

    protected function casts(): array
    {
        return ['full_day' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
