<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;

class PortalChallenge extends TenantRecord
{
    protected $table = 'recruitment_portal_challenges';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
