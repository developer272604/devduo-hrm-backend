<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class CalibrationChange extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_calibration_changes';

    protected function casts(): array
    {
        return ['from_score' => 'decimal:3', 'to_score' => 'decimal:3', 'decided_at' => 'datetime'];
    }
}
