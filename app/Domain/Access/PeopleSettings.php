<?php

namespace App\Domain\Access;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Tenant People configuration stored under tenants.settings.people:
 *   field_policies    {fieldKey: classification}         data-classification overrides
 *   edit_rules        {group: direct|request|none}       employee self-service rules
 *   directory_privacy {privacyKey: bool}                 what coworkers may see
 *   numbering         {...}                              see NumberingService
 *   completeness      {required: [rule keys]}            profile completeness rules
 *   statuses          {transitions: {...}}               optional lifecycle transition overrides
 */
final class PeopleSettings
{
    public static function all(?int $tenantId = null): array
    {
        $tenantId ??= app(TenantContext::class)->id;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        return $tenant?->settings['people'] ?? [];
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return data_get(self::all(), $path, $default);
    }

    public static function classification(string $field): string
    {
        $override = self::get('field_policies.'.$field);

        return $override && Classification::valid($override) ? $override : (FieldRegistry::FIELDS[$field][0] ?? Classification::HR_CONFIDENTIAL);
    }

    public static function editRule(string $group): string
    {
        return self::get('edit_rules.'.$group) ?? FieldRegistry::SELF_EDIT_DEFAULTS[$group] ?? 'none';
    }

    public static function directoryVisible(string $privacyKey): bool
    {
        return (bool) (self::get('directory_privacy.'.$privacyKey) ?? FieldRegistry::PRIVACY_DEFAULTS[$privacyKey] ?? false);
    }

    /** Replaces the people settings block (validated by the caller) inside the tenant settings JSON. */
    public static function save(array $people): void
    {
        $tenant = Tenant::whereKey(app(TenantContext::class)->id)->lockForUpdate()->firstOrFail();
        $settings = $tenant->settings ?? [];
        $settings['people'] = $people;
        $tenant->settings = $settings;
        $tenant->save();
    }
}
