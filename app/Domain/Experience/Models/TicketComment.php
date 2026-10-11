<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class TicketComment extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_ticket_comments';
}
