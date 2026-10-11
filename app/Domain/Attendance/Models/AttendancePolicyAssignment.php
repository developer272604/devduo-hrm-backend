<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

class AttendancePolicyAssignment extends TenantRecord
{
    protected $table = 'attendance_policy_assignments';

    protected function casts(): array
    {
        return ['is_override' => 'boolean', 'priority' => 'integer'];
    }

    public function policy()
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }
}
