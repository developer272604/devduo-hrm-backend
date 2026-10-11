<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class PipelineStage extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_pipeline_stages';
    protected function casts(): array { return ['reminder_recipients' => 'array']; }
}
