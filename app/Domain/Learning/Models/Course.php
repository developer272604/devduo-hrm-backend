<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Course extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_courses';

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean'];
    }
}
