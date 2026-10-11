<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class CustomFieldDefinition extends TenantRecord
{
    protected $table = 'custom_field_definitions';

    protected function casts(): array
    {
        return ['required' => 'boolean', 'validation' => 'array', 'employee_editable' => 'boolean', 'manager_editable' => 'boolean', 'hr_editable' => 'boolean',
            'countries' => 'array', 'employee_types' => 'array', 'effective_dated' => 'boolean', 'active' => 'boolean', 'sort' => 'integer'];
    }

    public function section()
    {
        return $this->belongsTo(CustomSection::class, 'section_id');
    }

    public function options()
    {
        return $this->hasMany(CustomFieldOption::class, 'definition_id')->orderBy('sort')->orderBy('id');
    }

    /** Decoded default value (stored as JSON text), or null. */
    public function defaultValue(): mixed
    {
        return $this->default_value === null ? null : json_decode($this->default_value, true);
    }
}
