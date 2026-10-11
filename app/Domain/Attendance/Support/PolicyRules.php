<?php

namespace App\Domain\Attendance\Support;

/**
 * The attendance-policy rule schema (the JSON stored in attendance_policy_versions.rules and in shifts.rules overrides).
 *
 * It is the single contract between policy administration, the calculation engine, the simulator and the explainer.
 * Every rule has an explicit default here; a stored policy only needs to contain what differs. Semantics are explicit,
 * never implied: e.g. late grace has a named `grace_mode`.
 *
 * Merge order (later wins): DEFAULTS < policy version rules < shift.rules overrides (only keys the shift defines).
 */
final class PolicyRules
{
    public const DEFAULTS = [
        // Which capture methods are allowed for this policy (tenant-level enablement is checked separately).
        'methods' => ['web', 'mobile', 'biometric', 'import', 'api', 'admin', 'kiosk'],

        'hours' => [
            'full_day_minutes' => 480,    // net work needed for a full day (used when the shift does not define it)
            'half_day_minutes' => 240,    // net work needed for a half day
            'minimum_minutes' => null,    // below this the day is "absent" even with punches; null = no floor
        ],

        // grace_mode: "beyond_grace" = lateness counted only for minutes AFTER the grace (09:18 vs 09:00+10 => 8)
        //             "from_start"   = once grace is exceeded the WHOLE delay counts (09:18 => 18); within grace => 0
        'late' => [
            'grace_minutes' => 10, 'grace_mode' => 'beyond_grace',
            'allowed_occurrences_per_month' => null,   // occurrences beyond this are flagged (action) — null = no allowance tracking
            'monthly_threshold_minutes' => null,        // cumulative late minutes per month that trigger escalation
            'half_day_after_minutes' => null,           // arrival later than this many minutes after start => half day at most
            'penalty_reference' => null,                // opaque reference for payroll/leave consequences (not interpreted here)
            'escalate_after_occurrences' => null,
        ],
        'early_exit' => [
            'grace_minutes' => 10, 'grace_mode' => 'beyond_grace',
            'allowed_occurrences_per_month' => null, 'threshold_minutes' => null, 'consequence_reference' => null,
        ],

        // mode: punch (OUT/IN pairs inside the day are breaks) | fixed (always deduct fixed_minutes) |
        //       auto_deduct (deduct after_minutes worked when no break punches exist) | none
        'breaks' => [
            'mode' => 'punch', 'fixed_minutes' => 0, 'paid' => false,
            'auto_deduct_after_minutes' => 360, 'auto_deduct_minutes' => 30,
            'max_breaks' => null, 'excessive_break_minutes' => null,
        ],

        // Missing punches are NEVER invented unless a tenant explicitly configures auto_fill (approved rule).
        'missing_punch' => ['auto_fill' => false, 'auto_fill_rule' => null, 'mark_status' => 'missing_punch'],

        // strategy: strict (trust IN/OUT) | alternating (UNKNOWN directions alternate IN,OUT,...) | first_last (first IN, last OUT)
        'pairing' => ['strategy' => 'strict', 'duplicate_window_seconds' => 60, 'max_session_minutes' => 1440],

        'overtime' => [
            'enabled' => true, 'pre_shift' => false, 'post_shift' => true, 'weekly_off' => true, 'holiday' => true, 'rest_day' => true,
            'minimum_minutes' => 30, 'maximum_minutes_per_day' => 240, 'rounding_minutes' => 15, 'rounding_mode' => 'down',
            'grace_minutes' => 0, 'threshold_minutes' => 0, 'requires_approval' => true, 'eligible_employee_type_ids' => null,
        ],

        'regularization' => [
            'enabled' => true, 'window_days' => 7, 'max_per_month' => null, 'allow_future' => false,
            'approval_steps' => ['manager'],       // manager | hr (ordered)
        ],

        'weekend' => ['work_allowed' => true],      // work on weekly off is allowed and tracked as overtime when eligible
        'holiday' => ['work_allowed' => true],
        'remote' => ['allowed' => true, 'requires_approval' => true],

        // geofence: anywhere | inside | remote_work (anywhere only when an approved remote/on-duty day) | approved_location
        'punch_restrictions' => [
            'geofence' => 'anywhere', 'require_photo' => false, 'capture_location' => false, 'allowed_ip_ranges' => [],
            'min_gap_seconds' => 60, 'max_punches_per_day' => 20,
        ],

        // A day counts as full when net >= required full-day minutes; half when net >= half-day minutes.
        // present_requires_core_hours: for flexible shifts, being present during core hours is also required.
        'status' => ['present_requires_core_hours' => true, 'future_days_pending' => true],

        // Cutoffs generate reminders/tasks, never silent failures.
        'cutoffs' => ['regularization_days_after' => 5, 'manager_approval_days' => 2],
    ];

    public const GRACE_MODES = ['beyond_grace', 'from_start'];

    public const BREAK_MODES = ['punch', 'fixed', 'auto_deduct', 'none'];

    public const PAIRING = ['strict', 'alternating', 'first_last'];

    public const GEOFENCE_MODES = ['anywhere', 'inside', 'remote_work', 'approved_location'];

    /** Deterministic merge: defaults < policy < shift overrides. Lists (methods, approval_steps) replace, maps merge. */
    public static function merge(array ...$layers): array
    {
        $out = self::DEFAULTS;
        foreach ($layers as $layer) {
            $out = self::mergeInto($out, $layer);
        }

        return $out;
    }

    private static function mergeInto(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null && array_key_exists($key, $base) && is_array($base[$key])) {
                continue; // an explicit null never wipes a whole section
            }
            $base[$key] = (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) && ! array_is_list($base[$key])) ? self::mergeInto($base[$key], $value) : $value;
        }

        return $base;
    }

    /** Returns friendly validation errors keyed by dotted path; empty = valid. */
    public static function validate(array $rules): array
    {
        $errors = [];
        $check = function (string $path, bool $ok, string $message) use (&$errors) {
            if (! $ok) {
                $errors[$path] = $message;
            }
        };
        $minutes = fn ($v) => $v === null || (is_int($v) && $v >= 0 && $v <= 1440);
        foreach (['late', 'early_exit'] as $section) {
            if (isset($rules[$section])) {
                $check("$section.grace_minutes", $minutes($rules[$section]['grace_minutes'] ?? 0), 'Grace must be between 0 and 1440 minutes.');
                $check("$section.grace_mode", in_array($rules[$section]['grace_mode'] ?? 'beyond_grace', self::GRACE_MODES, true), 'Grace mode must be "beyond_grace" or "from_start".');
            }
        }
        if (isset($rules['breaks']['mode'])) {
            $check('breaks.mode', in_array($rules['breaks']['mode'], self::BREAK_MODES, true), 'Break mode must be punch, fixed, auto_deduct or none.');
        }
        if (isset($rules['pairing']['strategy'])) {
            $check('pairing.strategy', in_array($rules['pairing']['strategy'], self::PAIRING, true), 'Pairing strategy must be strict, alternating or first_last.');
        }
        if (isset($rules['punch_restrictions']['geofence'])) {
            $check('punch_restrictions.geofence', in_array($rules['punch_restrictions']['geofence'], self::GEOFENCE_MODES, true), 'Location rule must be anywhere, inside, remote_work or approved_location.');
        }
        $full = $rules['hours']['full_day_minutes'] ?? self::DEFAULTS['hours']['full_day_minutes'];
        $half = $rules['hours']['half_day_minutes'] ?? self::DEFAULTS['hours']['half_day_minutes'];
        $check('hours.half_day_minutes', is_int($full) && is_int($half) && $half > 0 && $half < $full, 'Half-day hours must be above zero and less than full-day hours.');
        if (! empty($rules['overtime']['rounding_mode'])) {
            $check('overtime.rounding_mode', in_array($rules['overtime']['rounding_mode'], ['down', 'nearest', 'up'], true), 'Overtime rounding must be down, nearest or up.');
        }

        return $errors;
    }
}
