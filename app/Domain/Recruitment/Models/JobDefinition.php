<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class JobDefinition extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_jobs';
    
}
