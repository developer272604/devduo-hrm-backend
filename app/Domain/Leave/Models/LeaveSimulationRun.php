<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveSimulationRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_simulation_runs';

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array'];
    }
}
