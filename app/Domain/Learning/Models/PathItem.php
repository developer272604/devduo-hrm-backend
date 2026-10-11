<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class PathItem extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_path_items';

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }
}
