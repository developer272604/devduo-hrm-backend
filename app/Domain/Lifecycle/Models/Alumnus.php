<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Alumnus extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_alumni';

    protected function casts(): array
    {
        return ['last_working_date' => 'date:Y-m-d', 'rehire_eligible' => 'boolean'];
    }
}
