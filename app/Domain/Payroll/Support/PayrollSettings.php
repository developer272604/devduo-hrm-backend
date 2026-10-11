<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Tenant payroll configuration stored under tenants.settings.payroll. Read through this class so defaults live in one place
 * (same pattern as LeaveSettings / AttendanceSettings). Nothing here encodes a country law: statutory behaviour is country-pack DATA.
 * Section owners edit only their own DEFAULTS section (see tasks/phase-05/progress.md).
 *
 *   calculation   {engine_version, rounding_mode, internal_scale, negative_net: block|warn, zero_pay: warn|ignore}
 *   approvals     {run_checker, unlock_checker, bank_file_checker, manual_net_checker, mass_revision_checker, mass_revision_threshold}
 *   inputs        {require_approval_for: [source...], attendance_include_open, require_leave_batch, attendance_pending_policy: block|warn}
 *   validation    {variance_warn_percent, variance_block_percent, large_input_amount}
 *   privacy       {managers_see_compensation, reveal_requires_purpose, mask_amounts_in_notifications, audit_views}
 *   payslips      {publish_on, download_link_minutes, show_employer_contributions, show_ytd, branding{logo_path, footer}}
 *   payments      {require_confirmation_for_paid, allowed_formats, bank_file_retention_days}
 *   accounting    {reconcile_tolerance, require_mapping_for_all}
 *   currency      {allow_cross_currency, reporting_currency}
 *   exports       {retention_days, require_purpose}
 */
final class PayrollSettings
{
    public const ENGINE_VERSION = '1.0.0';

    public const DEFAULTS = [
        'calculation' => ['engine_version' => self::ENGINE_VERSION, 'rounding_mode' => Rounding::HALF_UP, 'internal_scale' => 6, 'negative_net' => 'block', 'zero_pay' => 'warn', 'days_per_year' => '260', 'hours_per_day' => '8', 'require_pack_verification' => true],
        'approvals' => ['run_checker' => true, 'unlock_checker' => true, 'bank_file_checker' => true, 'manual_net_checker' => true, 'mass_revision_checker' => true, 'mass_revision_threshold' => 25],
        'inputs' => ['require_approval_for' => ['bonus', 'commission', 'variable_pay', 'adjustment', 'reimbursement'], 'attendance_include_open' => false, 'require_leave_batch' => true, 'attendance_pending_policy' => 'block'],
        'validation' => ['variance_warn_percent' => '20', 'variance_block_percent' => null, 'large_input_amount' => null],
        'privacy' => ['managers_see_compensation' => false, 'reveal_requires_purpose' => true, 'mask_amounts_in_notifications' => true, 'audit_views' => true],
        'payslips' => ['publish_on' => 'lock', 'download_link_minutes' => 10, 'show_employer_contributions' => false, 'show_ytd' => true, 'branding' => ['logo_path' => null, 'footer' => null]],
        'payments' => ['require_confirmation_for_paid' => true, 'allowed_formats' => [], 'bank_file_retention_days' => 7, 'require_bank_verification' => true],
        'accounting' => ['reconcile_tolerance' => '0.00', 'require_mapping_for_all' => true],
        'currency' => ['allow_cross_currency' => false, 'reporting_currency' => null],
        'exports' => ['retention_days' => 3, 'require_purpose' => true],
    ];

    public static function all(?int $tenantId = null): array
    {
        $tenantId ??= app(TenantContext::class)->id;
        $stored = $tenantId ? (Tenant::find($tenantId)?->settings['payroll'] ?? []) : [];

        return self::merge(self::DEFAULTS, $stored);
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return data_get(self::all(), $path, $default);
    }

    /** Persists the whole payroll settings tree (callers validate sections first and merge-preserve other sections). */
    public static function save(array $settings): void
    {
        $tenant = Tenant::whereKey(app(TenantContext::class)->id)->lockForUpdate()->firstOrFail();
        $all = $tenant->settings ?? [];
        $all['payroll'] = $settings;
        $tenant->settings = $all;
        $tenant->save();
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) && ! array_is_list($base[$key])) ? self::merge($base[$key], $value) : $value;
        }

        return $base;
    }
}
