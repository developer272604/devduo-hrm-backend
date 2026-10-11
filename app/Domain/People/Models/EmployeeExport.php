<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeExport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_exports';

    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'selected_ids' => 'array', 'includes_sensitive' => 'boolean', 'expires_at' => 'datetime'];
    }
}
