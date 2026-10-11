<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ImportProfile extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_import_profiles';

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }
}
