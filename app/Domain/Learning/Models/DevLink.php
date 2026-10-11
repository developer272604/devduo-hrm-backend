<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class DevLink extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_dev_links';

    protected function casts(): array
    {
        return ['target_date' => 'date:Y-m-d'];
    }
}
