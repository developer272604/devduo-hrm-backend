<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Delivery extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_deliveries';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_attempt_at' => 'datetime'];
    }
}
