<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class AnnouncementDelivery extends TenantRecord
{
    protected $table = 'experience_announcement_deliveries';
    protected function casts(): array { return ['sent_at' => 'datetime', 'suppressed_at' => 'datetime']; }
}
