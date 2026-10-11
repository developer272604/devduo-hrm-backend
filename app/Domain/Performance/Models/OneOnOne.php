<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class OneOnOne extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_one_on_ones';

    protected function casts(): array
    {
        return ['shared_notes' => 'encrypted', 'private_notes' => 'encrypted', 'held_on' => 'date:Y-m-d', 'action_items' => 'array'];
    }
}
