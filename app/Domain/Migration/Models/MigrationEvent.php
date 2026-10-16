<?php

namespace App\Domain\Migration\Models;

use App\Domain\Tenancy\TenantRecord;

class MigrationEvent extends TenantRecord
{
    protected $table = 'migration_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
