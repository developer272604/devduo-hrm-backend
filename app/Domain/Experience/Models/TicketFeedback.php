<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class TicketFeedback extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_ticket_feedback';
}
