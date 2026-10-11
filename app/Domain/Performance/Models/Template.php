<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Template extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_templates';

    protected function casts(): array
    {
        return ['sections' => 'array', 'reviewer_weights' => 'array', 'active' => 'boolean'];
    }
    public function scale() { return $this->belongsTo(RatingScale::class, 'scale_id'); }
}
