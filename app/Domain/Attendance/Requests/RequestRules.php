<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\People\Models\Employee;

/**
 * Request rules for one employee and date. Precedence (later wins):
 *   PolicyRules::DEFAULTS < tenant AttendanceSettings (regularization window / monthly limit) < the resolved attendance policy.
 * The policy is read through App\Domain\Attendance\Policies\PolicyResolver when that class exists (it is built by the
 * policy workstream); when it does not, or fails, the defaults above apply, so requests never break on a missing policy.
 */
final class RequestRules
{
    public const POLICY_RESOLVER = 'App\\Domain\\Attendance\\Policies\\PolicyResolver';

    /** @return array{enabled:bool,window_days:int,max_per_month:?int,allow_future:bool,approval_steps:array<int,string>} */
    public static function regularization(Employee $employee, string $date): array
    {
        $rules = self::merged($employee, $date)['regularization'];
        $steps = array_values(array_filter((array) ($rules['approval_steps'] ?? ['manager']), fn ($s) => in_array($s, ['manager', 'hr'], true)));
        $rules['approval_steps'] = $steps ?: ['manager'];
        $rules['window_days'] = max(0, (int) ($rules['window_days'] ?? 7));

        return $rules;
    }

    public static function overtime(Employee $employee, string $date): array
    {
        return self::merged($employee, $date)['overtime'];
    }

    public static function remote(Employee $employee, string $date): array
    {
        return self::merged($employee, $date)['remote'];
    }

    public static function merged(Employee $employee, string $date): array
    {
        $tenant = [];
        foreach (['window_days', 'max_per_month'] as $key) {
            $value = AttendanceSettings::get('regularization.'.$key);
            if ($value !== null) {
                $tenant['regularization'][$key] = $value;
            }
        }

        return PolicyRules::merge($tenant, self::policy($employee, $date));
    }

    private static function policy(Employee $employee, string $date): array
    {
        if (! class_exists(self::POLICY_RESOLVER)) {
            return [];
        }
        try {
            $resolver = app(self::POLICY_RESOLVER);
            foreach (['rulesFor', 'effectiveRules', 'resolveRules'] as $method) {
                if (method_exists($resolver, $method)) {
                    $out = $resolver->$method($employee->id, $date);

                    return is_array($out) ? (array) ($out['rules'] ?? $out) : [];
                }
            }
        } catch (\Throwable) {
            // fall back to defaults
        }

        return [];
    }

    /** Request-cutoff settings (tenant level). */
    public static function setting(string $path, mixed $default = null): mixed
    {
        return AttendanceSettings::get($path, $default);
    }
}
