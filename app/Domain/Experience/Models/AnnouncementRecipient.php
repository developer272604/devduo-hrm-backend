<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class AnnouncementRecipient extends TenantRecord
{
    public $timestamps = false;
    protected $table = 'experience_announcement_recipients';
}
