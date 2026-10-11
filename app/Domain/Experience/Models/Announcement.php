<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Announcement extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_announcements';
    public function attachments() { return $this->hasMany(AnnouncementAttachment::class, 'announcement_id')->orderBy('id'); }
    protected function casts(): array { return ['acknowledgment_required' => 'boolean', 'publish_at' => 'date:Y-m-d', 'expires_at' => 'date:Y-m-d', 'event_starts_at' => 'datetime', 'event_ends_at' => 'datetime', 'notifications_sent_at' => 'datetime', 'audience_filters' => 'array', 'audience_all' => 'boolean', 'pinned' => 'boolean']; }
}
