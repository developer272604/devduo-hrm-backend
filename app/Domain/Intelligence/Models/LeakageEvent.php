<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\TenantRecord;

class LeakageEvent extends TenantRecord
{
    protected $table = 'intelligence_leakage_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
