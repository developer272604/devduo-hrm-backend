<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class WebhookDelivery extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_webhook_deliveries';

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
