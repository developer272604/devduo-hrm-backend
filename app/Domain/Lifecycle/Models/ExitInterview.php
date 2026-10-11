<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ExitInterview extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_exit_interviews';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'retention_until' => 'date:Y-m-d'];
    }
}
