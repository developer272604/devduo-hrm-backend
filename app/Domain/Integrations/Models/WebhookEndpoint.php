<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class WebhookEndpoint extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_webhook_endpoints';
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'allow_insecure' => 'boolean', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime', 'secret_rotated_at' => 'datetime'];
    }
}
