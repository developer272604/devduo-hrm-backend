<?php

namespace App\Domain\People\Config;

use App\Domain\People\Models\LookupValue;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant lookup values (lookup_values). Each kind ships with sensible system defaults that are seeded lazily and
 * idempotently per tenant. System rows keep their code forever and can be relabelled or (except employment
 * statuses) deactivated, never deleted. Custom values can be added to every kind except employment_status,
 * whose codes are the fixed internal lifecycle states (only the displayed LABEL is configurable).
 */
final class Lookups
{
    public const KINDS = [
        'employment_status' => 'Employment statuses', 'separation_reason' => 'Separation reasons', 'relationship_type' => 'Relationship types',
        'document_category' => 'Document categories', 'skill_category' => 'Skill categories', 'termination_reason' => 'Termination reasons',
    ];

    /** Kinds whose rows are fixed by the application: no new codes, no deactivation, label/sort/metadata only. */
    public const FIXED = ['employment_status'];

    private const DEFAULTS = [
        'separation_reason' => ['resignation' => 'Resignation', 'retirement' => 'Retirement', 'contract_end' => 'Contract end', 'termination' => 'Termination', 'absconding' => 'Absconding', 'other' => 'Other'],
        'relationship_type' => ['primary' => 'Primary manager', 'functional' => 'Functional manager', 'dotted_line' => 'Dotted-line manager', 'hr_partner' => 'HR partner'],
        'document_category' => ['identity' => 'Identity', 'employment' => 'Employment', 'education' => 'Education', 'experience' => 'Experience', 'contract' => 'Contract', 'certificate' => 'Certificate',
            'policy' => 'Policy', 'visa' => 'Visa', 'work_permit' => 'Work permit', 'tax' => 'Tax', 'bank' => 'Bank', 'other' => 'Other'],
        'skill_category' => ['technical' => 'Technical', 'functional' => 'Functional', 'leadership' => 'Leadership', 'language' => 'Language', 'tools' => 'Tools and platforms', 'soft_skill' => 'Soft skill', 'other' => 'Other'],
        'termination_reason' => ['misconduct' => 'Misconduct', 'performance' => 'Performance', 'redundancy' => 'Redundancy', 'policy_violation' => 'Policy violation', 'probation_not_confirmed' => 'Probation not confirmed', 'other' => 'Other'],
    ];

    public static function known(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    /** @return array<string,string> code => label shipped as system default for the kind. */
    public static function defaults(string $kind): array
    {
        return $kind === 'employment_status' ? StatusService::LABELS : (self::DEFAULTS[$kind] ?? []);
    }

    /** Idempotently seeds the system rows of a kind for the current (or given) tenant. Existing rows are never touched. */
    public static function ensureDefaults(string $kind, ?int $tenantId = null): void
    {
        $tenantId ??= app(TenantContext::class)->id;
        abort_unless($tenantId && self::known($kind), 422, 'Tenant context and a known lookup kind are required.');
        $existing = DB::table('lookup_values')->where('tenant_id', $tenantId)->where('kind', $kind)->pluck('code')->all();
        $rows = [];
        $sort = 0;
        foreach (self::defaults($kind) as $code => $label) {
            $sort += 10;
            if (! in_array($code, $existing, true)) {
                $rows[] = ['tenant_id' => $tenantId, 'kind' => $kind, 'code' => $code, 'label' => $label, 'active' => true, 'system' => true, 'sort' => $sort, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($rows) {
            DB::table('lookup_values')->insertOrIgnore($rows);
        }
    }

    public static function ensureAllDefaults(?int $tenantId = null): void
    {
        foreach (array_keys(self::KINDS) as $kind) {
            self::ensureDefaults($kind, $tenantId);
        }
    }

    /** Active values of a kind in display order (seeds defaults first). */
    public static function active(string $kind): Collection
    {
        self::ensureDefaults($kind);

        return LookupValue::query()->where('kind', $kind)->where('active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /** code => label for every stored row of a kind, including inactive ones (history must stay readable). Does not seed. */
    public static function labels(string $kind): array
    {
        return LookupValue::query()->where('kind', $kind)->orderBy('sort')->orderBy('id')->pluck('label', 'code')->all();
    }

    public static function label(string $kind, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return LookupValue::query()->where('kind', $kind)->where('code', $code)->value('label') ?? (self::defaults($kind)[$code] ?? $code);
    }

    /** Whether a code is an active value of the kind (e.g. to validate a separation reason on submission). */
    public static function isActive(string $kind, string $code): bool
    {
        self::ensureDefaults($kind);

        return LookupValue::query()->where('kind', $kind)->where('code', $code)->where('active', true)->exists();
    }

    /** Number of records elsewhere that reference the value. In-use values cannot be hard-deleted. */
    public static function usage(LookupValue $value): int
    {
        $tenant = $value->tenant_id;
        $count = fn (string $table, string $column) => DB::table($table)->where('tenant_id', $tenant)->where($column, $value->code)->count();

        return match ($value->kind) {
            'employment_status' => $count('employees', 'status'),
            'separation_reason' => $count('separation_records', 'type') + $count('separation_records', 'reason_code'),
            'termination_reason' => $count('separation_records', 'reason_code'),
            'relationship_type' => $count('employee_reporting_relationships', 'type'),
            'document_category' => $count('document_types', 'category'),
            'skill_category' => $count('skills', 'category'),
            default => 0,
        };
    }
}
