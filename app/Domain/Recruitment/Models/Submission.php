<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Submission extends TenantRecord
{
    use HasPublicId;

    protected $table = 'recruitment_submissions';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
