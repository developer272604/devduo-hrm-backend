<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Overtime request (pre-approval or post-work). calculated_minutes comes from the engine; approved_minutes is the human decision. */
class OvertimeRequest extends TenantRecord
{
    use HasPublicId;

    protected $table = 'overtime_requests';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
