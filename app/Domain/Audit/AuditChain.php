<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident audit trail. Every new audit row of a tenant gets a gapless sequence number, the hash of the
 * previous row and its own keyed hash (HMAC-SHA256 with a key derived from APP_KEY, so someone with database-only
 * access cannot recompute a valid chain). Editing, deleting, inserting or truncating chained rows is detectable
 * with verify(). Rows written before this feature (sequence NULL) are outside the chain.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function append(array $attributes): AuditLog
    {
        $tenantId = app(TenantContext::class)->id ?? throw new \LogicException('Tenant context is required.');

        return DB::transaction(function () use ($tenantId, $attributes) {
            DB::table('audit_chain_heads')->insertOrIgnore(['tenant_id' => $tenantId, 'last_sequence' => 0, 'last_hash' => self::GENESIS]);
            $head = DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $sequence = (int) $head->last_sequence + 1;
            $createdAt = now()->format('Y-m-d H:i:s');
            $hash = self::hash($head->last_hash, $tenantId, $sequence, $attributes + ['created_at' => $createdAt]);
            $row = AuditLog::create($attributes + ['created_at' => $createdAt, 'sequence' => $sequence, 'prev_hash' => $head->last_hash, 'row_hash' => $hash]);
            DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->update(['last_sequence' => $sequence, 'last_hash' => $hash, 'updated_at' => now()]);

            return $row;
        });
    }

    /** Verifies the current tenant's chain (tenant context required). */
    public static function verify(): array
    {
        $tenantId = app(TenantContext::class)->id ?? throw new \LogicException('Tenant context is required.');
        $failures = [];
        $checked = 0;
        $previousHash = null;
        $previousSequence = null;
        $first = null;
        foreach (AuditLog::whereNotNull('sequence')->orderBy('sequence')->cursor() as $row) {
            $first ??= (int) $row->sequence;
            $sequence = (int) $row->sequence;
            if ($previousSequence !== null && $sequence !== $previousSequence + 1) {
                $failures[] = ['sequence' => $sequence, 'reason' => 'missing_rows'];
            }
            if ($previousHash === null ? ($sequence === 1 && $row->prev_hash !== self::GENESIS) : $row->prev_hash !== $previousHash) {
                $failures[] = ['sequence' => $sequence, 'reason' => 'chain_break'];
            }
            $expected = self::hash((string) $row->prev_hash, $tenantId, $sequence, [
                'user_id' => $row->user_id, 'actor' => $row->actor, 'action' => $row->action, 'module' => $row->module, 'resource' => $row->resource,
                'ip_address' => $row->ip_address, 'context' => $row->context ?? [], 'created_at' => $row->getRawOriginal('created_at'),
            ]);
            if (! hash_equals($expected, (string) $row->row_hash)) {
                $failures[] = ['sequence' => $sequence, 'reason' => 'row_modified'];
            }
            $previousHash = (string) $row->row_hash;
            $previousSequence = $sequence;
            $checked++;
        }
        $head = DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->first();
        if ($head && ((int) $head->last_sequence !== (int) $previousSequence || ($previousHash !== null && $head->last_hash !== $previousHash))) {
            $failures[] = ['sequence' => (int) $head->last_sequence, 'reason' => 'tail_truncated_or_altered'];
        }
        $unchained = $first === null ? 0 : AuditLog::whereNull('sequence')->where('id', '>', (int) AuditLog::where('sequence', $first)->value('id'))->count();

        return ['ok' => $failures === [], 'checked' => $checked, 'first_sequence' => $first, 'last_sequence' => $previousSequence, 'failures' => array_slice($failures, 0, 50),
            'failure_count' => count($failures), 'unchained_rows_after_start' => $unchained, 'verified_at' => now()->toIso8601String()];
    }

    private static function hash(string $previous, int $tenantId, int $sequence, array $a): string
    {
        $payload = self::canonical([
            't' => $tenantId, 's' => $sequence, 'u' => isset($a['user_id']) ? (int) $a['user_id'] : null, 'a' => (string) $a['actor'], 'ac' => (string) $a['action'],
            'm' => (string) $a['module'], 'r' => (string) $a['resource'], 'ip' => isset($a['ip_address']) ? (string) $a['ip_address'] : null,
            'c' => json_decode(json_encode($a['context'] ?? []), true), 'at' => (string) $a['created_at'],
        ]);

        return hash_hmac('sha256', $previous.'|'.$payload, hash('sha256', 'audit-chain|'.config('app.key')));
    }

    /** Key-order independent JSON (MySQL JSON columns reorder object keys). */
    private static function canonical(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
