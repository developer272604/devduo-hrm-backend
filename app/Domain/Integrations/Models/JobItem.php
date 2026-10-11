<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class JobItem extends TenantRecord
{

    protected $table = 'integration_job_items';

    protected function casts(): array
    {
        return [];
    }
}
