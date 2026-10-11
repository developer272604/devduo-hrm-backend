<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AttendanceException extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_exceptions';

    public const OPEN_STATES = ['open', 'acknowledged'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
