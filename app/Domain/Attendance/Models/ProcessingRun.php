<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ProcessingRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_processing_runs';

    protected function casts(): array
    {
        return ['scope' => 'array', 'errors' => 'array'];
    }
}
