<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class SeparationRecord extends TenantRecord
{
    use HasPublicId;

    protected $table = 'separation_records';

    protected function casts(): array
    {
        return ['initiated_on' => 'date:Y-m-d', 'last_working_date' => 'date:Y-m-d', 'rehire_eligible' => 'boolean'];
    }
}
