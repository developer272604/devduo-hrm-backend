<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Attendance\Capture\UtcInstant;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Interview extends TenantRecord
{
    use HasPublicId;

    protected $table = 'recruitment_interviews';

    protected function casts(): array
    {
        return ['starts_at' => UtcInstant::class, 'ends_at' => UtcInstant::class];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function panelists()
    {
        return $this->hasMany(Panelist::class, 'interview_id');
    }

    public function scorecards()
    {
        return $this->hasMany(Scorecard::class, 'interview_id');
    }
}
