<?php

namespace App\Domain\Quality;

use App\Domain\Tenancy\TenantRecord;

class DataQualityFinding extends TenantRecord
{
    protected $table = 'data_quality_findings';

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'dismissed'];

    /** Statuses that count as "still a problem". */
    public const ACTIVE = ['open', 'acknowledged'];

    protected function casts(): array
    {
        return ['details' => 'array', 'detected_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }
}
