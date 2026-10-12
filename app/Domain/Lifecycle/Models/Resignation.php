<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Resignation extends TenantRecord
{
    use HasPublicId;
    protected $table = 'lifecycle_resignations';
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'decision_note' => 'encrypted', 'withdrawal_reason' => 'encrypted', 'requested_last_day' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }
}
