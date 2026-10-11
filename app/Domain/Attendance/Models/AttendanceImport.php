<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AttendanceImport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_imports';

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(AttendanceImportRow::class, 'import_id');
    }
}
