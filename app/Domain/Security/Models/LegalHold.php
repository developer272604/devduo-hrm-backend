<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class LegalHold extends TenantRecord
{
    use HasPublicId;

    protected $table = 'legal_holds';

    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
