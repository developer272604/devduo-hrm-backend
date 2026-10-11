<?php

namespace App\Domain\Organization;

use App\Domain\Tenancy\TenantRecord;

class OrganizationRecord extends TenantRecord
{
    public const TYPES = ['legal-entities' => 'legal_entities', 'locations' => 'locations', 'business-units' => 'business_units', 'departments' => 'departments', 'teams' => 'teams', 'cost-centers' => 'cost_centers', 'designations' => 'designations', 'positions' => 'positions', 'grades' => 'grades', 'levels' => 'levels', 'employee-types' => 'employee_types'];

    public static function forType(string $type): self
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return (new self)->setTable(self::TYPES[$type]);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'metadata' => 'array'];
    }
}
