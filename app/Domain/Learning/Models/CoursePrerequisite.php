<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class CoursePrerequisite extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_course_prerequisites';
}
