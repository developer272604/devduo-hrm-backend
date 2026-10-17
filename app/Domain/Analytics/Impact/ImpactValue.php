<?php

namespace App\Domain\Analytics\Impact;

use App\Domain\Tenancy\TenantRecord;

class ImpactValue extends TenantRecord
{
    protected $table = 'impact_values';

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'value' => 'decimal:4'];
    }
}
