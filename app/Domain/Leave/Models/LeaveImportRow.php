<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveImportRow extends TenantRecord
{
    protected $table = 'leave_import_rows';

    protected function casts(): array
    {
        return ['raw' => 'array', 'normalized' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
