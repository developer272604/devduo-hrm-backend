<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Path extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_paths';
}
