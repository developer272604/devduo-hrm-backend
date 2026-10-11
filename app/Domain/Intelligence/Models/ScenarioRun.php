<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ScenarioRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'intelligence_scenario_runs';

    protected function casts(): array
    {
        return ['params' => 'array', 'result' => 'array'];
    }
}
