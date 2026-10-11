<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Scorecard extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_scorecards';
    protected function casts(): array { return ['strengths' => 'encrypted', 'concerns' => 'encrypted']; }
}
