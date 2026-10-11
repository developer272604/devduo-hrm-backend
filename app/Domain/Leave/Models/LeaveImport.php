<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveImport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_imports';

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(LeaveImportRow::class, 'import_id');
    }
}
