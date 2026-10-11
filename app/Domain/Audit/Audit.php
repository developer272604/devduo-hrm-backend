<?php

namespace App\Domain\Audit;

final class Audit
{
    public static function record(string $action, string $module, string $resource, array $context = []): void
    {
        AuditChain::append(['user_id' => auth()->id(), 'actor' => auth()->user()?->name ?? 'System', 'action' => $action, 'module' => $module, 'resource' => $resource, 'ip_address' => request()->ip(), 'context' => $context]);
    }
}
