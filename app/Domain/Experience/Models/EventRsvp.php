<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class EventRsvp extends TenantRecord
{
    protected $table = 'experience_event_rsvps';
}
