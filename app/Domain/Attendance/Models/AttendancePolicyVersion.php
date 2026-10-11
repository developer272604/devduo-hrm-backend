<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

class AttendancePolicyVersion extends TenantRecord
{
    protected $table = 'attendance_policy_versions';

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function policy()
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }

    /** "India General Attendance Policy v3" - shown on every attendance day and in explanations. */
    public function label(): string
    {
        return ($this->policy?->name ?? 'Attendance policy').' v'.$this->version;
    }
}
