<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Pipeline extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_pipelines';
    protected function casts(): array { return ['is_default' => 'boolean', 'allow_skip' => 'boolean', 'used_at' => 'datetime']; }
    public function stages() { return $this->hasMany(PipelineStage::class, 'pipeline_id')->orderBy('position'); }
}
