<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Geofence extends TenantRecord
{
    use HasPublicId;

    protected $table = 'geofences';

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_meters' => 'integer', 'active' => 'boolean'];
    }
}
