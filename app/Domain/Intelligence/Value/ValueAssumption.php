<?php

namespace App\Domain\Intelligence\Value;

use App\Domain\Tenancy\TenantRecord;

class ValueAssumption extends TenantRecord
{
    protected $table = 'value_workflow_assumptions';

    protected function casts(): array
    {
        return ['hourly_cost' => 'decimal:2'];
    }
}
