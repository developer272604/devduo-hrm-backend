<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveRequest extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_requests';

    protected function casts(): array
    {
        return ['warnings' => 'array', 'explanation' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'attendance_synced_at' => 'datetime'];
    }

    public function segments()
    {
        return $this->hasMany(LeaveRequestSegment::class, 'request_id');
    }

    public function actions()
    {
        return $this->hasMany(LeaveRequestAction::class, 'request_id');
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
