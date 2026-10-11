<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class BulkRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'bulk_runs';

    protected function casts(): array
    {
        return ['params' => 'array', 'employee_ids' => 'array', 'results' => 'array', 'token_expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
