<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Handover extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_handovers';
    
}
