<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class CourseVersion extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_course_versions';

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
