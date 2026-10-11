<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** One preview/confirm/apply run of the attendance bulk runner. */
class AttendanceBulkRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_bulk_runs';

    protected function casts(): array
    {
        return ['params' => 'array', 'targets' => 'array', 'preview' => 'array', 'results' => 'array', 'token_expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
