<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class CustomFieldOption extends TenantRecord
{
    protected $table = 'custom_field_options';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }
}
