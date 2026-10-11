<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Enrolment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_enrolments';

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'decided_at' => 'datetime', 'completed_at' => 'datetime', 'waitlisted_at' => 'datetime'];
    }

    public function course() { return $this->belongsTo(Course::class, 'course_id'); }
}
