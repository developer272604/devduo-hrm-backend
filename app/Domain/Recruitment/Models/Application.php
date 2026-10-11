<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Application extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_applications';
    public function candidate() { return $this->belongsTo(Candidate::class); }
    public function requisition() { return $this->belongsTo(Requisition::class); }
}
