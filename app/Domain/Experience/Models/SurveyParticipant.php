<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class SurveyParticipant extends TenantRecord
{
    public $timestamps = false;
    protected $table = 'experience_survey_participants';
    protected function casts(): array { return ['submitted' => 'boolean', 'consented' => 'boolean']; }
}
