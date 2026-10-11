<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class RatingScale extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_rating_scales';

    protected function casts(): array
    {
        return ['labels' => 'array', 'active' => 'boolean'];
    }
}
