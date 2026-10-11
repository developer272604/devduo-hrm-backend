<?php

namespace App\Domain\People\Services;

use App\Domain\Access\PeopleSettings;
use Illuminate\Support\Facades\DB;

/**
 * Configurable, transaction-safe employee numbering.
 *
 * settings.people.numbering = {
 *   mode: 'auto'|'manual',            pattern: 'EMP-{SEQ:5}',  // tokens: {SEQ:n} {LE} {LOC} {TYPE} {COUNTRY} {YYYY} {YY}
 *   scope: 'tenant'|'legal_entity'|'location'|'employee_type', start: 1,
 *   rules: [{ match: {employee_type_id|legal_entity_id|location_id|worker_type: value}, pattern: 'CTR-{SEQ:5}' }]  // first match wins
 * }
 * Each scope has its own counter row, locked with SELECT ... FOR UPDATE so concurrent creations
 * receive distinct values; employees(tenant_id, employee_number) is also unique as a last line of defence.
 */
final class NumberingService
{
    public const DEFAULT = ['mode' => 'auto', 'pattern' => 'EMP-{SEQ:5}', 'scope' => 'tenant', 'start' => 1, 'rules' => []];

    public function config(): array
    {
        return array_replace(self::DEFAULT, PeopleSettings::get('numbering', []));
    }

    public function isManual(): bool
    {
        return $this->config()['mode'] === 'manual';
    }

    /**
     * Reserves and returns the next employee number for the given context
     * (employee_type_id, legal_entity_id, location_id, worker_type, country). Call inside the creating transaction.
     */
    public function next(array $context): string
    {
        $config = $this->config();
        $pattern = $config['pattern'];
        foreach ($config['rules'] ?? [] as $rule) {
            if ($this->matches($rule['match'] ?? [], $context)) {
                $pattern = $rule['pattern'];
                break;
            }
        }
        $scope = $config['scope'];
        $key = match ($scope) {
            'legal_entity' => 'le:'.($context['legal_entity_id'] ?? 0), 'location' => 'loc:'.($context['location_id'] ?? 0),
            'employee_type' => 'type:'.($context['employee_type_id'] ?? 0), default => 'tenant',
        };
        $key .= '|'.md5($pattern);
        $tenantId = app(\App\Domain\Tenancy\TenantContext::class)->id;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $value = $this->take($tenantId, $key, (int) $config['start']);
            $number = $this->render($pattern, $value, $context);
            if (! DB::table('employees')->where('tenant_id', $tenantId)->where('employee_number', $number)->exists()) {
                return $number;
            }
        }
        abort(422, 'We could not allocate a free employee number. Check your numbering pattern.');
    }

    /** Preview what the next number would look like without consuming the sequence. */
    public function preview(array $context): string
    {
        $config = $this->config();
        $tenantId = app(\App\Domain\Tenancy\TenantContext::class)->id;
        $pattern = $config['pattern'];
        foreach ($config['rules'] ?? [] as $rule) {
            if ($this->matches($rule['match'] ?? [], $context)) {
                $pattern = $rule['pattern'];
                break;
            }
        }
        $key = match ($config['scope']) {
            'legal_entity' => 'le:'.($context['legal_entity_id'] ?? 0), 'location' => 'loc:'.($context['location_id'] ?? 0),
            'employee_type' => 'type:'.($context['employee_type_id'] ?? 0), default => 'tenant',
        }.'|'.md5($pattern);
        $next = DB::table('employee_number_sequences')->where('tenant_id', $tenantId)->where('scope_key', $key)->value('next_value') ?? (int) $config['start'];

        return $this->render($pattern, (int) $next, $context);
    }

    private function take(int $tenantId, string $key, int $start): int
    {
        return DB::transaction(function () use ($tenantId, $key, $start) {
            DB::table('employee_number_sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'scope_key' => $key, 'next_value' => $start, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('employee_number_sequences')->where('tenant_id', $tenantId)->where('scope_key', $key)->lockForUpdate()->first();
            DB::table('employee_number_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    private function matches(array $match, array $context): bool
    {
        foreach ($match as $field => $expected) {
            if (($context[$field] ?? null) != $expected) {
                return false;
            }
        }

        return $match !== [];
    }

    private function render(string $pattern, int $value, array $context): string
    {
        $codes = fn (string $table, ?int $id) => $id ? (DB::table($table)->where('id', $id)->value('code') ?? '') : '';
        $out = preg_replace_callback('/\{SEQ:(\d+)\}/', fn ($m) => str_pad((string) $value, (int) $m[1], '0', STR_PAD_LEFT), $pattern);
        $out = str_replace(['{SEQ}', '{LE}', '{LOC}', '{TYPE}', '{COUNTRY}', '{YYYY}', '{YY}'],
            [(string) $value, $codes('legal_entities', $context['legal_entity_id'] ?? null), $codes('locations', $context['location_id'] ?? null), $codes('employee_types', $context['employee_type_id'] ?? null),
                strtoupper($context['country'] ?? ''), now()->format('Y'), now()->format('y')], $out);

        return mb_substr($out, 0, 60);
    }
}
