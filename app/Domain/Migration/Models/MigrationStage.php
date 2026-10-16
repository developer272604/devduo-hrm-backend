<?php

namespace App\Domain\Migration\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class MigrationStage extends TenantRecord
{
    use HasPublicId;

    protected $table = 'migration_stages';

    protected function casts(): array
    {
        return ['import_snapshot' => 'array', 'verified_at' => 'datetime'];
    }

    public function reconciliations()
    {
        return $this->hasMany(MigrationReconciliation::class, 'stage_id')->orderBy('id');
    }
}
