<?php

namespace App\Domain\Leave\Support;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Tenant leave configuration stored under tenants.settings.leave. Read through this class so defaults live in one place
 * (same pattern as AttendanceSettings). Nothing here encodes a country's law: statutory behaviour is policy DATA.
 *
 *   units              {minutes_per_day, minutes_per_half_day, minutes_per_quarter_day, rounding}   conversion used by the duration engine when a shift gives no length
 *   balance            {allow_negative_default, default_negative_limit, maker_checker}
 *   requests           {backdate_days, future_days, advance_notice_days, allow_withdraw_after_approval, require_reason}
 *   approvals          {steps (manager|hr...), escalate_after_hours, auto_approve_for_hr}
 *   accrual            {run_day_of_month, catch_up_days, nightly_enabled, auto_carry_forward}
 *   expiry             {notify_days_before: []}
 *   comp_off           {require_approval, default_validity_days}
 *   privacy            {managers_see_reason, managers_see_medical, medical_retention_days}
 *   restrictions       {never_block_statutory}
 *   integration        {sync_attendance, payroll_contract, cutoff_day}
 */
final class LeaveSettings
{
    public const DEFAULTS = [
        'units' => ['minutes_per_day' => 480, 'minutes_per_half_day' => 240, 'minutes_per_quarter_day' => 120, 'rounding' => 'nearest'],
        'balance' => ['allow_negative_default' => false, 'default_negative_limit' => '0.0000', 'carry_negative_forward' => true, 'maker_checker' => true],
        'requests' => ['backdate_days' => 7, 'future_days' => 365, 'advance_notice_days' => 0, 'pending_expiry_days' => 90, 'allow_withdraw_after_approval' => true, 'require_reason' => false],
        'approvals' => ['steps' => ['manager'], 'escalate_after_hours' => 48, 'auto_approve_for_hr' => false],
        'accrual' => ['run_day_of_month' => 1, 'catch_up_days' => 3, 'nightly_enabled' => true, 'auto_carry_forward' => false],
        'expiry' => ['notify_days_before' => [30, 7]],
        'comp_off' => ['require_approval' => true, 'default_validity_days' => 90],
        'privacy' => ['managers_see_reason' => false, 'managers_see_medical' => false, 'medical_retention_days' => 2555],
        'restrictions' => ['never_block_statutory' => true],
        'integration' => ['sync_attendance' => true, 'payroll_contract' => true, 'cutoff_day' => null],
    ];

    public static function all(?int $tenantId = null): array
    {
        $tenantId ??= app(TenantContext::class)->id;
        $stored = $tenantId ? (Tenant::find($tenantId)?->settings['leave'] ?? []) : [];

        return self::merge(self::DEFAULTS, $stored);
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return data_get(self::all(), $path, $default);
    }

    public static function save(array $settings): void
    {
        $tenant = Tenant::whereKey(app(TenantContext::class)->id)->lockForUpdate()->firstOrFail();
        $all = $tenant->settings ?? [];
        $all['leave'] = $settings;
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
