<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class SurveyConfidentialResponse extends TenantRecord
{
    public $timestamps = false;
    protected $table = 'experience_survey_confidential_responses';
}
