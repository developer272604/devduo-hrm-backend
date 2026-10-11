<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class AnnouncementRead extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_announcement_reads';
    protected function casts(): array { return ['read_at' => 'datetime', 'acknowledged_at' => 'datetime']; }
}
