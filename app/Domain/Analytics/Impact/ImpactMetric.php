<?php

namespace App\Domain\Analytics\Impact;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ImpactMetric extends TenantRecord
{
    use HasPublicId;

    protected $table = 'impact_metrics';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
