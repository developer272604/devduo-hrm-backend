<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SurveyScale extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_survey_scales';
    protected function casts(): array { return ['labels' => 'array', 'active' => 'boolean']; }
}
