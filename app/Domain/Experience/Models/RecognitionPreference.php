<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class RecognitionPreference extends TenantRecord
{
    protected $table = 'experience_recognition_preferences';
    protected function casts(): array { return ['opted_out' => 'boolean']; }
}
