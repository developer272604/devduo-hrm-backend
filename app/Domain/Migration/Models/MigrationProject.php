<?php

namespace App\Domain\Migration\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class MigrationProject extends TenantRecord
{
    use HasPublicId;

    protected $table = 'migration_projects';

    protected function casts(): array
    {
        return ['target_cutover_on' => 'date:Y-m-d', 'went_live_at' => 'datetime'];
    }

    public function stages()
    {
        return $this->hasMany(MigrationStage::class, 'project_id')->orderBy('position');
    }
}
