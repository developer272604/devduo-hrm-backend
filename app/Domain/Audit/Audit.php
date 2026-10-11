<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;

final class Audit
{
    public static function record(string $action, string $module, string $resource, array $context = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'actor' => auth()->user()?->name ?? 'System', 'action' => $action, 'module' => $module, 'resource' => $resource, 'ip_address' => request()->ip(), 'context' => $context]);
    }
}
