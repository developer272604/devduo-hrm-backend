<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;

final class TenantRunner
{
    /** Background jobs must carry a trusted tenant ID and establish context before using tenant records. */
    public function run(int $tenantId, callable $callback): mixed
    {
        Tenant::findOrFail($tenantId);
        $context = app(TenantContext::class);
        $previous = $context->id;
        $context->id = $tenantId;
        try {
            return $callback();
        } finally {
            $context->id = $previous;
        }
    }
}
