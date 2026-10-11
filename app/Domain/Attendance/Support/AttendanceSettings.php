<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Tenant attendance configuration stored under tenants.settings.attendance (edited through the attendance
 * settings API). Read through this class so defaults are applied in one place.
 *
 *   methods            list of ENABLED capture methods (web|mobile|biometric|import|api|admin|kiosk)
 *   location           {mode: off|policy|required, retention_days, purpose, managers_see_precise}
 *   status_labels      {status code => display label}   (internal status codes never change)
 *   regularization     {window_days, max_per_month}
 *   cutoffs            {payroll_cutoff_day, regularization_days_after, manager_approval_days, lock_day}
 *   notifications      {check_out_reminder_minutes, missing_punch_after_hours, shift_starting_soon_minutes, roster_published, quiet_hours}
 *   punch              {duplicate_window_seconds, server_time_only: true}
 *   processing         {nightly_recalculation_days, async_threshold_days}
 *   compliance         {enabled, country_overrides}   (see ComplianceRules)
 */
final class AttendanceSettings
{
    public const DEFAULTS = [
        'methods' => ['web', 'import', 'admin'],
        'location' => ['mode' => 'off', 'retention_days' => 90, 'purpose' => 'Verify that attendance is recorded from an approved place of work.', 'managers_see_precise' => false],
        'status_labels' => [],
        'regularization' => ['window_days' => 7, 'max_per_month' => null],
        'cutoffs' => ['payroll_cutoff_day' => null, 'regularization_days_after' => 5, 'manager_approval_days' => 2, 'lock_day' => null],
        'notifications' => ['check_out_reminder_minutes' => 30, 'missing_punch_after_hours' => 4, 'shift_starting_soon_minutes' => 60, 'roster_published' => true],
        'punch' => ['duplicate_window_seconds' => 60, 'server_time_only' => true],
        'processing' => ['nightly_recalculation_days' => 3, 'async_threshold_days' => 31],
        'compliance' => ['enabled' => true],
    ];

    public static function all(?int $tenantId = null): array
    {
        $tenantId ??= app(TenantContext::class)->id;
        $stored = $tenantId ? (Tenant::find($tenantId)?->settings['attendance'] ?? []) : [];

        return self::merge(self::DEFAULTS, $stored);
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return data_get(self::all(), $path, $default);
    }

    public static function methodEnabled(string $method): bool
    {
        return in_array($method, self::get('methods', []), true);
    }

    public static function statusLabels(): array
    {
        return self::get('status_labels', []);
    }

    public static function save(array $settings): void
    {
        $tenant = Tenant::whereKey(app(TenantContext::class)->id)->lockForUpdate()->firstOrFail();
        $all = $tenant->settings ?? [];
        $all['attendance'] = $settings;
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
