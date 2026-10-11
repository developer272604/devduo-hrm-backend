<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

class AttendanceImportRow extends TenantRecord
{
    protected $table = 'attendance_import_rows';

    protected function casts(): array
    {
        return ['raw' => 'array', 'normalized' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
