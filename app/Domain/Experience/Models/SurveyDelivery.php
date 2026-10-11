<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class SurveyDelivery extends TenantRecord
{
    protected $table = 'experience_survey_deliveries';
    protected function casts(): array
    {
        return ['invitation_processed_at' => 'datetime', 'invited_at' => 'datetime', 'invitation_suppressed_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }
}
