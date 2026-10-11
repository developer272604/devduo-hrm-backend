<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class CandidateImport extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_imports';
    protected function casts(): array { return ['rows' => 'encrypted:array', 'results' => 'encrypted:array', 'expires_at' => 'datetime']; }
}
