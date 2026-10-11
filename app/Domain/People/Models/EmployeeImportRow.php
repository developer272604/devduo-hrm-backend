<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class EmployeeImportRow extends TenantRecord
{
    protected $table = 'employee_import_rows';

    protected function casts(): array
    {
        return ['raw' => 'array', 'normalized' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
