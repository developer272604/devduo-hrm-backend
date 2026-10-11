<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class AnnouncementAttachment extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_announcement_attachments';
}
