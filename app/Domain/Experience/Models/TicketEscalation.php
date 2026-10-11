<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class TicketEscalation extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_ticket_escalations';
    protected function casts(): array { return ['notified_at' => 'datetime']; }
}
