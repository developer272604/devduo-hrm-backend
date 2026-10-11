<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class CompensationRevisionBatch extends TenantRecord
{
    use HasPublicId;

    protected $table = 'compensation_revision_batches';

    protected function casts(): array
    {
        return ['params' => 'array', 'preview' => 'encrypted:array', 'errors' => 'array', 'approved_at' => 'datetime'];
    }

    public function revisions()
    {
        return $this->hasMany(CompensationRevision::class, 'batch_id');
    }
}
