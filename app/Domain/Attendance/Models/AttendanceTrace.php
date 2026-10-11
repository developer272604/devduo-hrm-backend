<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

/** Immutable calculation trace: one row per material change of a day. */
class AttendanceTrace extends TenantRecord
{
    protected $table = 'attendance_calculation_traces';

    protected function casts(): array
    {
        return ['inputs' => 'array', 'steps' => 'array', 'result' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Calculation traces are immutable.'));
        static::deleting(fn () => throw new \LogicException('Calculation traces are immutable.'));
    }
}
