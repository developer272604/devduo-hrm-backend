<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class SavedView extends TenantRecord
{
    use HasPublicId;

    protected $table = 'saved_views';

    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'sort' => 'array', 'shared' => 'boolean'];
    }
}
