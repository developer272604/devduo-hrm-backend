<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Posting extends TenantRecord
{
    use HasPublicId;

    protected $table = 'recruitment_postings';

    protected function casts(): array
    {
        return ['closes_on' => 'date', 'public_enabled' => 'boolean'];
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }
}
