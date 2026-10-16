<?php

namespace App\Domain\Intelligence\Leakage;

use App\Domain\Intelligence\Models\LeakageSettings;

/** Rule catalogue, default thresholds and per-tenant overrides. Every threshold is explained to the reviewer in the case's explain payload. */
final class LeakageRules
{
    public const CATALOG = [
        'ot_spike' => ['label' => 'Overtime spike', 'severity' => 'warning', 'sources' => ['attendance'],
            'description' => 'Approved overtime in the window is a multiple of the employee\'s own trailing average.'],
        'ot_over_calculated' => ['label' => 'Approved overtime above calculated', 'severity' => 'warning', 'sources' => ['attendance'],
            'description' => 'Overtime was approved for more minutes than the attendance engine calculated from punches.'],
        'regularization_overtime' => ['label' => 'Repeated regularization with overtime', 'severity' => 'action_required', 'sources' => ['attendance'],
            'description' => 'Many approved punch regularizations in the same window as approved overtime.'],
        'duplicate_expense' => ['label' => 'Possible duplicate expense', 'severity' => 'action_required', 'sources' => ['expenses'],
            'description' => 'Two or more submitted claims contain a line with the same merchant, date, amount and currency.'],
        'repeat_payroll_adjustment' => ['label' => 'Repeated manual payroll adjustments', 'severity' => 'warning', 'sources' => ['payroll'],
            'description' => 'The same employee received several manual or correction payroll adjustments in the window.'],
    ];

    public const DEFAULTS = [
        'ot_spike_ratio' => 2.0, 'ot_spike_min_minutes' => 600,
        'ot_over_calculated_min_minutes' => 120,
        'regularization_min_count' => 3, 'regularization_overtime_min_minutes' => 300,
        'repeat_adjustment_min_count' => 3,
    ];

    public static function thresholds(): array
    {
        return array_replace(self::DEFAULTS, LeakageSettings::query()->first()?->thresholds ?? []);
    }

    public static function disabled(): array
    {
        return LeakageSettings::query()->first()?->disabled_rules ?? [];
    }
}
