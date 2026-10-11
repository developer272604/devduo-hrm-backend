<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Checkin extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_checkins';

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'progress_before' => 'decimal:2', 'progress_after' => 'decimal:2'];
    }
}
