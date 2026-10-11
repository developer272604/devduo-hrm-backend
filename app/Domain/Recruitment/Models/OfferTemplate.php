<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class OfferTemplate extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_offer_templates';
}
