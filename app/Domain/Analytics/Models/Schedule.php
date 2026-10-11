<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Schedule extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_schedules';

    protected function casts(): array
    {
        return ['recipient_ids' => 'array', 'channels' => 'array', 'active' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }
}
