<?php

namespace App\Domain\Security;

use Illuminate\Support\Facades\DB;

/** Per-tenant security settings with safe defaults. Reads need no tenant context so the pre-login flow can use it. */
final class SecurityPolicy
{
    public const DEFAULTS = [
        'mfa' => ['enforcement' => 'off', 'role_ids' => []],
        'session' => ['idle_minutes' => 0, 'absolute_minutes' => 0, 'max_concurrent' => 0],
        'password' => ['min_length' => 12, 'history' => 0, 'expiry_days' => 0, 'lockout_attempts' => 10, 'lockout_minutes' => 15],
        'audit' => ['retention_days' => 2555],
        'sso' => ['protocol' => 'none', 'enabled' => false, 'issuer' => null, 'client_id' => null, 'metadata_url' => null, 'domain_hints' => []],
    ];

    public static function for(int $tenantId): array
    {
        $row = DB::table('security_policies')->where('tenant_id', $tenantId)->value('settings');
        $stored = $row ? (json_decode($row, true) ?: []) : [];
        $policy = array_replace_recursive(self::DEFAULTS, $stored);
        // List-valued keys must be replaced, not merged index by index.
        $policy['mfa']['role_ids'] = array_values($stored['mfa']['role_ids'] ?? []);
        $policy['sso']['domain_hints'] = array_values($stored['sso']['domain_hints'] ?? []);

        return $policy;
    }

    public static function save(int $tenantId, array $settings, ?int $userId): void
    {
        $now = now();
        $payload = json_encode($settings);
        if (DB::table('security_policies')->where('tenant_id', $tenantId)->exists()) {
            DB::table('security_policies')->where('tenant_id', $tenantId)->update(['settings' => $payload, 'updated_by' => $userId, 'updated_at' => $now]);
        } else {
            DB::table('security_policies')->insert(['tenant_id' => $tenantId, 'settings' => $payload, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
