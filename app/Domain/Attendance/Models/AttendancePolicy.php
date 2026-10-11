<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AttendancePolicy extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_policies';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function versions()
    {
        return $this->hasMany(AttendancePolicyVersion::class, 'policy_id')->orderBy('version');
    }
}
