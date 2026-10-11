<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Tenant-configurable lookup value (separation reasons, relationship types, document categories, ...). */
class LookupValue extends TenantRecord
{
    protected $table = 'lookup_values';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'system' => 'boolean', 'sort' => 'integer', 'metadata' => 'array'];
    }
}
