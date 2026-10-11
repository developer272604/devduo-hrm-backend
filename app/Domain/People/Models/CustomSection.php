<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class CustomSection extends TenantRecord
{
    protected $table = 'custom_sections';

    protected function casts(): array
    {
        return ['countries' => 'array', 'employee_types' => 'array', 'active' => 'boolean', 'sort' => 'integer'];
    }

    public function fields()
    {
        return $this->hasMany(CustomFieldDefinition::class, 'section_id')->orderBy('sort')->orderBy('id');
    }
}
