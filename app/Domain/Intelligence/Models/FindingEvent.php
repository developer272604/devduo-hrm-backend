<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\TenantRecord;

class FindingEvent extends TenantRecord
{
    public $timestamps = false;

    protected $table = 'intelligence_finding_events';

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
