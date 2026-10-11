<?php

namespace App\Domain\Leave\Types;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave type catalogue. Types carry classification (kind, category, unit, sensitivity) and APPLICABILITY data only; entitlement
 * and behaviour live in policy versions. Nothing here encodes a country's law; "statutory" is a flag the tenant sets.
 */
final class TypeService
{
    public const KINDS = ['annual', 'casual', 'sick', 'medical', 'parental', 'maternity', 'paternity', 'bereavement', 'marriage', 'study', 'sabbatical', 'unpaid', 'comp_off', 'optional_holiday', 'custom'];

    public const CATEGORIES = ['paid', 'unpaid', 'statutory', 'company'];

    public const UNITS = ['day', 'half_day', 'quarter_day', 'hour'];

    /** Generic, country-neutral starter types (no entitlement; HR adds amounts in a policy). */
    public const DEFAULTS = [
        ['code' => 'ANNUAL', 'name' => 'Annual leave', 'kind' => 'annual', 'category' => 'paid', 'unit' => 'day'],
        ['code' => 'CASUAL', 'name' => 'Casual leave', 'kind' => 'casual', 'category' => 'paid', 'unit' => 'day'],
        ['code' => 'SICK', 'name' => 'Sick leave', 'kind' => 'sick', 'category' => 'paid', 'unit' => 'day'],
        ['code' => 'UNPAID', 'name' => 'Unpaid leave', 'kind' => 'unpaid', 'category' => 'unpaid', 'unit' => 'day', 'tracks_balance' => false],
        ['code' => 'COMPOFF', 'name' => 'Compensatory off', 'kind' => 'comp_off', 'category' => 'company', 'unit' => 'day'],
        ['code' => 'OPTIONAL', 'name' => 'Optional holiday', 'kind' => 'optional_holiday', 'category' => 'company', 'unit' => 'day'],
    ];

    public function create(array $data): LeaveType
    {
        $row = $this->normalize($data);
        if (LeaveType::query()->where('code', $row['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A leave type with this code already exists.']);
        }
        $type = LeaveType::create($row);
        Audit::record('created', 'leave', 'leave_type:'.$type->uuid, ['code' => $type->code, 'kind' => $type->kind]);

        return $type;
    }

    public function update(LeaveType $type, array $data): LeaveType
    {
        $merged = array_merge($type->only(['name', 'code', 'kind', 'category', 'unit', 'tracks_balance', 'is_statutory', 'is_sensitive', 'color', 'sort_order', 'config', 'description', 'active']), $data);
        $row = $this->normalize($merged);
        if ($row['code'] !== $type->code && LeaveType::query()->where('code', $row['code'])->where('id', '!=', $type->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'A leave type with this code already exists.']);
        }
        foreach (['code', 'unit', 'tracks_balance'] as $field) {
            if ($row[$field] !== (is_bool($row[$field]) ? (bool) $type->{$field} : $type->{$field}) && $this->isReferenced($type)) {
                throw ValidationException::withMessages([$field => 'This leave type is already used by policies, accounts or requests, so "'.$field.'" can no longer change. Create a new type or deactivate this one.']);
            }
        }
        $type->fill($row)->save();
        Audit::record('updated', 'leave', 'leave_type:'.$type->uuid, array_keys($data));

        return $type;
    }

    public function delete(LeaveType $type): void
    {
        if ($this->isReferenced($type)) {
            throw ValidationException::withMessages(['type' => 'This leave type is used by policies, accounts or requests. Deactivate it instead of deleting so history stays intact.']);
        }
        Audit::record('deleted', 'leave', 'leave_type:'.$type->uuid, ['code' => $type->code]);
        $type->delete();
    }

    public function isReferenced(LeaveType $type): bool
    {
        foreach (['leave_policy_type_rules', 'leave_accounts', 'leave_requests', 'leave_ledger_entries', 'leave_document_rules', 'leave_restrictions'] as $table) {
            if (DB::table($table)->where('tenant_id', $type->tenant_id)->where('leave_type_id', $type->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Idempotent: creates only the codes that do not exist. @return array{created:array<int,string>,skipped:array<int,string>} */
    public function seedDefaults(): array
    {
        $created = $skipped = [];
        foreach (self::DEFAULTS as $i => $def) {
            if (LeaveType::query()->where('code', $def['code'])->exists()) {
                $skipped[] = $def['code'];

                continue;
            }
            $this->create($def + ['sort_order' => $i * 10]);
            $created[] = $def['code'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function normalize(array $d): array
    {
        $kind = $d['kind'] ?? 'custom';
        $category = $d['category'] ?? 'paid';
        $unit = $d['unit'] ?? 'day';
        foreach ([['kind', $kind, self::KINDS], ['category', $category, self::CATEGORIES], ['unit', $unit, self::UNITS]] as [$field, $value, $allowed]) {
            if (! in_array($value, $allowed, true)) {
                throw ValidationException::withMessages([$field => ucfirst($field).' must be one of '.implode(', ', $allowed).'.']);
            }
        }
        $config = $this->config($d['config'] ?? null);
        $statutory = (bool) ($d['is_statutory'] ?? $category === 'statutory');

        return ['name' => $d['name'], 'code' => strtoupper((string) $d['code']), 'kind' => $kind, 'category' => $category, 'unit' => $unit,
            'tracks_balance' => (bool) ($d['tracks_balance'] ?? true), 'is_statutory' => $statutory, 'is_sensitive' => (bool) ($d['is_sensitive'] ?? in_array($kind, ['medical'], true)),
            'color' => $d['color'] ?? null, 'sort_order' => (int) ($d['sort_order'] ?? 0), 'config' => $config, 'description' => $d['description'] ?? null, 'active' => (bool) ($d['active'] ?? true)];
    }

    /** Applicability and evidence configuration. */
    private function config(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }
        $allowed = ['countries', 'legal_entities', 'employee_types', 'evidence', 'limits', 'half_day_allowed', 'notes', 'holiday_pool'];
        $unknown = array_diff(array_keys($config), $allowed);
        if ($unknown) {
            throw ValidationException::withMessages(['config.'.reset($unknown) => 'Unknown configuration "'.reset($unknown).'".']);
        }
        foreach (['countries', 'legal_entities', 'employee_types'] as $list) {
            if (isset($config[$list]) && (! is_array($config[$list]) || ! array_is_list($config[$list]))) {
                throw ValidationException::withMessages(["config.$list" => 'Must be a list.']);
            }
        }
        foreach ($config['countries'] ?? [] as $i => $c) {
            if (! is_string($c) || ! preg_match('/^[A-Za-z]{2}$/', $c)) {
                throw ValidationException::withMessages(['config.countries' => 'Use two-letter country codes.']);
            }
            $config['countries'][$i] = strtoupper($c);
        }
        if (isset($config['evidence'])) {
            $e = $config['evidence'];
            if (! is_array($e) || (isset($e['required']) && ! is_bool($e['required'])) || (isset($e['after_units']) && ! is_numeric($e['after_units']))) {
                throw ValidationException::withMessages(['config.evidence' => 'Evidence needs required (true/false) and optionally after_units (a number).']);
            }
        }

        return $config;
    }
}
