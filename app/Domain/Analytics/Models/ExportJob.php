<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ExportJob extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_export_jobs';

    protected function casts(): array
    {
        return ['spec' => 'array', 'masked_columns' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
