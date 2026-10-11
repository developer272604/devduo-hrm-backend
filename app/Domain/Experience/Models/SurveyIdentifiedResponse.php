<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class SurveyIdentifiedResponse extends TenantRecord
{
    public $timestamps = false;
    protected $table = 'experience_survey_identified_responses';
}
