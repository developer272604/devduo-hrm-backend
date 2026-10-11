<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class AccessReviewCampaign extends TenantRecord
{
    use HasPublicId;

    protected $table = 'access_review_campaigns';

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }
}
