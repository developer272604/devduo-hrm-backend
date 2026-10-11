<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Session extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_sessions';

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'waitlist_enabled' => 'boolean'];
    }
}
