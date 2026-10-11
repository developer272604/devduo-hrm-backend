<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Resume extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_resumes';
    protected function casts(): array { return ['purged_at' => 'datetime']; }
}
