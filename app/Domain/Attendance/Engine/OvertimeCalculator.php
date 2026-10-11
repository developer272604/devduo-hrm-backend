<?php

namespace App\Domain\Attendance\Engine;

/**
 * Step (h): overtime FOUNDATION (calculation only, no payment).
 *
 * Per type (pre_shift, post_shift, weekly_off, holiday, rest_day) the raw minutes go through, in this order:
 *  1. type flag (`overtime.<type>`) must be true;
 *  2. grace:      raw <= grace_minutes  => 0, otherwise the raw minutes count in full;
 *  3. threshold:  threshold_minutes are subtracted (OT starts after the threshold); never below 0;
 *  4. minimum:    result < minimum_minutes => 0;
 *  5. rounding:   to a multiple of rounding_minutes with rounding_mode down | nearest (half rounds up) | up.
 * Then types are added in the fixed order above and the running total is capped at `maximum_minutes_per_day`
 * (later types are reduced first). Calculated OT only; approved OT is carried elsewhere.
 */
final class OvertimeCalculator
{
    public const TYPES = ['pre_shift', 'post_shift', 'weekly_off', 'holiday', 'rest_day'];

    /**
     * @param  array<string,int>  $rawMinutes  by type
     * @return array{total:int,breakdown:array<string,int>,per_type:array<string,array<string,mixed>>}
     */
    public static function compute(array $rawMinutes, array $rules): array
    {
        $perType = [];
        $breakdown = array_fill_keys(self::TYPES, 0);
        $grace = (int) ($rules['grace_minutes'] ?? 0);
        $threshold = (int) ($rules['threshold_minutes'] ?? 0);
        $minimum = (int) ($rules['minimum_minutes'] ?? 0);
        $round = (int) ($rules['rounding_minutes'] ?? 0);
        $mode = (string) ($rules['rounding_mode'] ?? 'down');
        $max = $rules['maximum_minutes_per_day'] ?? null;

        $total = 0;
        foreach (self::TYPES as $type) {
            $raw = (int) ($rawMinutes[$type] ?? 0);
            if ($raw <= 0) {
                continue;
            }
            $info = ['raw' => $raw];
            if (empty($rules[$type])) {
                $info['outcome'] = 'type_disabled';
                $perType[$type] = $info + ['minutes' => 0];

                continue;
            }
            $m = $raw;
            if ($m <= $grace) {
                $perType[$type] = $info + ['outcome' => 'within_grace', 'minutes' => 0];

                continue;
            }
            $m = max(0, $m - $threshold);
            if ($m < $minimum || $m === 0) {
                $perType[$type] = $info + ['outcome' => 'below_minimum', 'after_threshold' => $m, 'minutes' => 0];

                continue;
            }
            $rounded = self::round($m, $round, $mode);
            $allowed = $max === null ? $rounded : max(0, min($rounded, (int) $max - $total));
            $info += ['after_threshold' => $m, 'rounded' => $rounded, 'outcome' => $allowed < $rounded ? 'capped' : 'counted', 'minutes' => $allowed];
            $perType[$type] = $info;
            $breakdown[$type] = $allowed;
            $total += $allowed;
        }

        return ['total' => $total, 'breakdown' => $breakdown, 'per_type' => $perType];
    }

    public static function round(int $minutes, int $step, string $mode): int
    {
        if ($step <= 1) {
            return $minutes;
        }

        return match ($mode) {
            'up' => (int) (ceil($minutes / $step) * $step),
            'nearest' => intdiv($minutes + intdiv($step, 2), $step) * $step,
            default => intdiv($minutes, $step) * $step,
        };
    }
}
