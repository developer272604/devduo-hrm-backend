<?php

namespace App\Models;

use App\Domain\Tenancy\TenantRecord;

class AuditLog extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
