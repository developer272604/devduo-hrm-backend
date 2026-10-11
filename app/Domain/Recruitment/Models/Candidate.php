<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Candidate extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_candidates';
    protected function casts(): array { return ['email' => 'encrypted', 'phone' => 'encrypted', 'anonymized_at' => 'datetime']; }
}
