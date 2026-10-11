<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Finding extends TenantRecord
{
    use HasPublicId;

    protected $table = 'intelligence_findings';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'explain' => 'array', 'due_on' => 'date:Y-m-d', 'snoozed_until' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
