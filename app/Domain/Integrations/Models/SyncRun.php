<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class SyncRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_sync_runs';

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
