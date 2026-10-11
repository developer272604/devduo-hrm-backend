<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Feedback extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_feedback';
}
