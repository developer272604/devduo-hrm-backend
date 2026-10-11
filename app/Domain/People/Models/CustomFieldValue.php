<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** One stored value (JSON text) of a custom field for an employee or person, optionally effective-dated. */
class CustomFieldValue extends TenantRecord
{
    protected $table = 'custom_field_values';

    /** Sentinel start date used by fields that are not effective-dated. */
    public const ORIGIN = '1900-01-01';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function decoded(): mixed
    {
        return $this->value === null ? null : json_decode($this->value, true);
    }

    public function definition()
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }
}
