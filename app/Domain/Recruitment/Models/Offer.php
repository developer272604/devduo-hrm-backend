<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Offer extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_offers';
    protected function casts(): array { return ['compensation_reference' => 'encrypted', 'notes' => 'encrypted', 'start_date' => 'date', 'expires_on' => 'date']; }
    public function application() { return $this->belongsTo(Application::class); }
}
