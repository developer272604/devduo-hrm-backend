<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\TenantRecord;

class LeakageSettings extends TenantRecord
{
    protected $table = 'intelligence_leakage_settings';

    protected function casts(): array
    {
        return ['thresholds' => 'array', 'disabled_rules' => 'array'];
    }
}
