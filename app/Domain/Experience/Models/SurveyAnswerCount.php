<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class SurveyAnswerCount extends TenantRecord
{
    public $timestamps = false;
    protected $table = 'experience_survey_answer_counts';
}
