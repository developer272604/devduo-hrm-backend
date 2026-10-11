<?php
namespace App\Domain\Travel\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class TravelAdvance extends TenantRecord {
    use HasPublicId;
    protected $table = 'travel_advances';
    protected function casts(): array { return ['amount_minor' => 'integer', 'minor_units' => 'integer', 'reviewed_at' => 'datetime', 'referenced_at' => 'datetime']; }
}
