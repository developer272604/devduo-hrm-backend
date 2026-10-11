<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Manual adjustment. Raw punches are never modified; the processor reads applied/approved rows. */
class AttendanceAdjustment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_adjustments';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'requires_checker' => 'boolean', 'checked_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
