<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** A catalog skill assigned to a person (unique per person + skill). Addressed by the skill id in API paths. */
class EmployeeSkill extends TenantRecord
{
    protected $table = 'employee_skills';

    protected function casts(): array
    {
        return ['years_experience' => 'float', 'last_used' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function certification()
    {
        return $this->belongsTo(EmployeeCertification::class, 'certification_id');
    }
}
