<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AttendanceDay extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_days';

    protected function casts(): array
    {
        return ['missing_punch' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function traces()
    {
        return $this->hasMany(AttendanceTrace::class, 'day_id')->orderBy('calculation_version');
    }

    public function policyVersion()
    {
        return $this->belongsTo(AttendancePolicyVersion::class, 'policy_version_id');
    }
}
