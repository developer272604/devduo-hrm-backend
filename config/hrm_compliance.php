<?php

/*
 |--------------------------------------------------------------------------
 | Working-time compliance: COUNTRY CONFIGURATION layer
 |--------------------------------------------------------------------------
 | Three layers stay separate (see App\Domain\Attendance\Scheduling\ComplianceRules):
 |   1. global engine   - generic checks that know nothing about any country (no country is hard-coded in engine code)
 |   2. country config  - THIS FILE: default "packs" a tenant can start from
 |   3. tenant policy   - compliance_rule_overrides rows and settings.attendance.compliance (enforcement flag|block|off)
 |
 | !!  ILLUSTRATIVE DEFAULTS - CONFIGURATION, NOT LEGAL ADVICE.  !!
 | Labour law differs by sector, contract, region and collective agreement, and it changes. Review every value with qualified
 | local counsel before relying on it; tenants are expected to override these per country. A null value disables that check.
 |
 | Rule keys (all optional):
 |   max_daily_minutes           scheduled net work per day above which a day is flagged
 |   max_weekly_minutes          scheduled net work per ISO week (Mon-Sun) above which the week is flagged
 |   min_rest_minutes            minimum gap between the end of one shift and the start of the next
 |   weekly_rest_minutes         minimum uninterrupted rest that must exist within each ISO week
 |   night_window_start/end      local clock window treated as night (e.g. 22:00-06:00); night_min_overlap_minutes = overlap that makes a shift a night shift
 |   max_consecutive_nights      night shifts in a row above which a run is flagged
 |   standard_weekly_minutes + max_weekly_overtime_minutes   scheduled time above the standard week that exceeds the overtime limit is flagged
 |   break_after_minutes + break_minimum_minutes   shifts of at least break_after_minutes need at least break_minimum_minutes of break
 */
return [
    'disclaimer' => 'Illustrative defaults - configuration, not legal advice. Verify with qualified local counsel and override per country in your compliance settings.',

    'night_min_overlap_minutes' => 180,

    'countries' => [
        'IN' => ['name' => 'India', 'rules' => [
            'max_daily_minutes' => 540, 'max_weekly_minutes' => 2880, 'min_rest_minutes' => 480, 'weekly_rest_minutes' => 1440,
            'night_window_start' => '22:00', 'night_window_end' => '06:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2880, 'max_weekly_overtime_minutes' => 750,
            'break_after_minutes' => 300, 'break_minimum_minutes' => 30,
        ]],
        'US' => ['name' => 'United States', 'rules' => [
            // Federal rules set overtime pay thresholds rather than hard limits; many states add meal/rest and rest-day rules.
            'max_daily_minutes' => null, 'max_weekly_minutes' => null, 'min_rest_minutes' => null, 'weekly_rest_minutes' => null,
            'night_window_start' => null, 'night_window_end' => null, 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2400, 'max_weekly_overtime_minutes' => null,
            'break_after_minutes' => null, 'break_minimum_minutes' => null,
        ]],
        'GB' => ['name' => 'United Kingdom', 'rules' => [
            'max_daily_minutes' => null, 'max_weekly_minutes' => 2880, 'min_rest_minutes' => 660, 'weekly_rest_minutes' => 1440,
            'night_window_start' => '23:00', 'night_window_end' => '06:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => null, 'max_weekly_overtime_minutes' => null,
            'break_after_minutes' => 360, 'break_minimum_minutes' => 20,
        ]],
        'AE' => ['name' => 'United Arab Emirates', 'rules' => [
            'max_daily_minutes' => 480, 'max_weekly_minutes' => 2880, 'min_rest_minutes' => null, 'weekly_rest_minutes' => 1440,
            'night_window_start' => '22:00', 'night_window_end' => '04:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2880, 'max_weekly_overtime_minutes' => 840,
            'break_after_minutes' => 300, 'break_minimum_minutes' => 60,
        ]],
        'DE' => ['name' => 'Germany', 'rules' => [
            'max_daily_minutes' => 600, 'max_weekly_minutes' => 3600, 'min_rest_minutes' => 660, 'weekly_rest_minutes' => 1440,
            'night_window_start' => '23:00', 'night_window_end' => '06:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2880, 'max_weekly_overtime_minutes' => null,
            'break_after_minutes' => 360, 'break_minimum_minutes' => 30,
        ]],
        'SG' => ['name' => 'Singapore', 'rules' => [
            'max_daily_minutes' => 720, 'max_weekly_minutes' => null, 'min_rest_minutes' => null, 'weekly_rest_minutes' => 1440,
            'night_window_start' => '22:00', 'night_window_end' => '06:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2640, 'max_weekly_overtime_minutes' => 1080,
            'break_after_minutes' => 360, 'break_minimum_minutes' => 45,
        ]],
        'AU' => ['name' => 'Australia', 'rules' => [
            'max_daily_minutes' => 720, 'max_weekly_minutes' => 2280, 'min_rest_minutes' => 600, 'weekly_rest_minutes' => null,
            'night_window_start' => '22:00', 'night_window_end' => '06:00', 'max_consecutive_nights' => null,
            'standard_weekly_minutes' => 2280, 'max_weekly_overtime_minutes' => null,
            'break_after_minutes' => 300, 'break_minimum_minutes' => 30,
        ]],
    ],
];
