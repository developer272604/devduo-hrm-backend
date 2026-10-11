<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SurveyBankQuestion extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_survey_question_bank';
    protected function casts(): array { return ['active' => 'boolean']; }
}
