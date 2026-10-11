<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class EmployeeImport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'employee_imports';

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array', 'headers' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }
}
