<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Attendance extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_attendance';

    protected function casts(): array
    {
        return ['session_date' => 'date:Y-m-d', 'present' => 'boolean'];
    }
}
