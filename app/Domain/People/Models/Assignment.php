<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class Assignment extends TenantRecord
{
    protected $table = 'employee_assignments';

    /** Organization dimensions carried by every assignment row (column => Phase 1 org table). */
    public const DIMENSIONS = ['legal_entity_id' => 'legal_entities', 'business_unit_id' => 'business_units', 'department_id' => 'departments', 'team_id' => 'teams', 'cost_center_id' => 'cost_centers',
        'designation_id' => 'designations', 'position_id' => 'positions', 'grade_id' => 'grades', 'level_id' => 'levels', 'employee_type_id' => 'employee_types', 'location_id' => 'locations'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
