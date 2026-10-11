<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class JobRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_job_runs';
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'next_retry_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
