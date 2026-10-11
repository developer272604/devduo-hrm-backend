<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Requisition extends TenantRecord
{
    use HasPublicId;

    protected $table = 'recruitment_requisitions';

    protected function casts(): array
    {
        return ['confidential' => 'boolean', 'target_date' => 'date', 'opened_at' => 'datetime', 'filled_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function job()
    {
        return $this->belongsTo(JobDefinition::class, 'job_id');
    }

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class);
    }
}
