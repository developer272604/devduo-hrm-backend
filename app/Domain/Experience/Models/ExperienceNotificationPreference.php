<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class ExperienceNotificationPreference extends TenantRecord
{
    protected $table = 'experience_notification_preferences';
    protected function casts(): array
    {
        return ['announcements_in_app' => 'boolean', 'announcements_email' => 'boolean', 'surveys_in_app' => 'boolean', 'surveys_email' => 'boolean',
            'recognition_in_app' => 'boolean', 'recognition_email' => 'boolean', 'events_in_app' => 'boolean', 'events_email' => 'boolean'];
    }
}
