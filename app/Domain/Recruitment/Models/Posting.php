<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Posting extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_postings';
    public function requisition() { return $this->belongsTo(Requisition::class); }
}
