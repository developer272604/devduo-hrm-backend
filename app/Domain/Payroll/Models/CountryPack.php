<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class CountryPack extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_country_packs';

    protected function casts(): array
    {
        return [];
    }

    public function versions()
    {
        return $this->hasMany(CountryPackVersion::class, 'pack_id');
    }
}
