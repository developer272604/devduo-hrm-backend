<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class KeyResult extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_key_results';

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'progress' => 'decimal:2', 'start_value' => 'decimal:4', 'target_value' => 'decimal:4', 'current_value' => 'decimal:4'];
    }
}
