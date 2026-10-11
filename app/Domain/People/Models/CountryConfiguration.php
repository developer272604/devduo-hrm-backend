<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Tenant override (or tenant-defined) country pack. Merged over config/hrm_countries.php by CountryConfig. */
class CountryConfiguration extends TenantRecord
{
    protected $table = 'country_configurations';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'address_format' => 'array', 'identifier_types' => 'array', 'bank_schema' => 'array', 'employment_fields' => 'array', 'statutory_fields' => 'array'];
    }
}
