<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Assessment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_assessments';

    protected function casts(): array
    {
        return ['questions' => 'array', 'active' => 'boolean'];
    }
}
