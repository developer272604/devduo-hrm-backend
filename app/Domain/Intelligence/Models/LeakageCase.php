<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class LeakageCase extends TenantRecord
{
    use HasPublicId;

    protected $table = 'intelligence_leakage_cases';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'explain' => 'array', 'window_from' => 'date:Y-m-d', 'window_to' => 'date:Y-m-d', 'exposure_amount' => 'decimal:4', 'recovered_amount' => 'decimal:4',
            'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
