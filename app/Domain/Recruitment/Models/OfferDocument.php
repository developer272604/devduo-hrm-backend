<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class OfferDocument extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_offer_documents';
    protected function casts(): array { return ['content' => 'encrypted', 'compensation_snapshot' => 'encrypted', 'purged_at' => 'datetime']; }
    public function offer() { return $this->belongsTo(Offer::class); }
}
