<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AttendanceLock extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_locks';

    public const SCOPES = ['tenant', 'legal_entity', 'payroll_group', 'location', 'employee_group', 'employee'];

    /** Breadth of a scope: a broader lock makes a narrower one over the same period redundant. */
    public const BREADTH = ['tenant' => 6, 'legal_entity' => 5, 'payroll_group' => 4, 'location' => 3, 'employee_group' => 2, 'employee' => 1];
}
