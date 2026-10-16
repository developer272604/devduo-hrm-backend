<?php

namespace App\Domain\Migration\Models;

use App\Domain\Tenancy\TenantRecord;

class MigrationReconciliation extends TenantRecord
{
    protected $table = 'migration_reconciliations';

    protected function casts(): array
    {
        return ['source_value' => 'decimal:4', 'tolerance' => 'decimal:4', 'imported_value' => 'decimal:4', 'variance' => 'decimal:4', 'checked_at' => 'datetime'];
    }
}
