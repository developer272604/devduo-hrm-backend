<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class TicketAttachment extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_ticket_attachments';
}
