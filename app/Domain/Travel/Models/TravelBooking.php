<?php
namespace App\Domain\Travel\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class TravelBooking extends TenantRecord {
    use HasPublicId;
    protected $table = 'travel_bookings';
    protected function casts(): array { return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'cost_minor' => 'integer', 'minor_units' => 'integer', 'cancelled_at' => 'datetime']; }
}
