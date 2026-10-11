<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Engine\DaySchedule;
use App\Domain\Attendance\Engine\Intervals;
use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;

/**
 * Reusable roster/swap/bulk-assign conflict detection (spec 73-75). Generic checks only; country numbers come from
 * ComplianceRules (country config + tenant policy). Violations are FLAGGED; `level` is "block" only when the tenant enforcement is "block".
 *
 * Conflict: {employee_id, date, code, rule, layer: global|country|tenant, severity: warning|action_required, level: flag|block, message}
 */
final class ConflictDetector
{
    public function __construct(private ComplianceRules $compliance, private DatabaseScheduleResolver $resolver) {}

    /**
     * @param  array<int,int>  $employeeIds
     * @param  array<int,array<string,array{entry_type:string,shift_id:?int}>>  $overlay  proposed entries replacing the published roster
     * @return array<int,array<string,mixed>>
     */
    public function detect(array $employeeIds, string $from, string $to, array $overlay = []): array
    {
        $settings = $this->compliance->settings();
        $employeeIds = array_values(array_unique($employeeIds));
        if (! $employeeIds) {
            return [];
        }
        // Keep a full week around the requested interval so consecutive-night rules at its boundaries are explainable.
        $wideFrom = CarbonImmutable::parse($from)->subDays(7)->toDateString();
        $wideTo = CarbonImmutable::parse($to)->addDays(7)->toDateString();
        $decider = ScheduleDecider::load($employeeIds, $wideFrom, $wideTo, $overlay);
        $block = $settings['enforcement'] === 'block';
        $engineOn = $settings['enabled'] && $settings['enforcement'] !== 'off';
        $unavailable = app()->bound(UnavailabilityProvider::class) ? app(UnavailabilityProvider::class)->unavailable($employeeIds, $from, $to) : [];
        $out = [];
        $add = function (int $emp, string $date, string $code, string $rule, string $layer, string $severity, string $message, bool $structural = false) use (&$out, $block) {
            $out[] = ['employee_id' => $emp, 'date' => $date, 'code' => $code, 'rule' => $rule, 'layer' => $layer, 'severity' => $severity, 'level' => $block ? 'block' : 'flag', 'message' => $message];
        };

        foreach ($employeeIds as $emp) {
            $employee = $decider->org->employee($emp);
            $name = $employee['employee_number'] ?? '#'.$emp;
            /** @var array<string,DaySchedule> $days */
            $days = [];
            foreach (DatabaseScheduleResolver::dates($wideFrom, $wideTo) as $date) {
                $days[$date] = $this->resolver->materialize($decider->decide($emp, $date));
            }
            $consecutiveNights = 0;
            foreach ($days as $date => $schedule) {
                $ctx = $decider->org->at($emp, $date);
                $effective = $engineOn ? $this->compliance->forCountry($ctx['country'] ?? null) : ['rules' => [], 'layers' => []];
                $rules = $effective['rules'];
                $maximum = (int) ($rules['max_consecutive_nights'] ?? 0);
                $overlapLimit = (int) ($rules['night_min_overlap_minutes'] ?? 180);
                $nightMinutes = $maximum > 0 && $schedule->kind === 'working'
                    ? $this->nightOverlapMinutes($schedule, $rules)
                    : 0;
                if ($nightMinutes >= $overlapLimit && $overlapLimit > 0) {
                    $consecutiveNights++;
                    if ($consecutiveNights === $maximum + 1 && $date >= $from && $date <= $to) {
                        $layer = $effective['layers']['max_consecutive_nights'] ?? 'country';
                        $add($emp, $date, 'max_consecutive_nights', 'Maximum consecutive night shifts', $layer, 'warning', "{$name} is scheduled for more than {$maximum} consecutive night shifts by {$date}.");
                    }
                } else {
                    $consecutiveNights = 0;
                }
            }
            // Proposed entries outside employment (the resolver reports those days as not scheduled).
            foreach ($overlay[$emp] ?? [] as $date => $entry) {
                if ($date < $from || $date > $to || ($entry['entry_type'] ?? '') === 'cleared') {
                    continue;
                }
                if ($employee && ($date < $employee['joined_on'] || ($employee['separated_on'] && $date > $employee['separated_on']))) {
                    $add($emp, $date, 'outside_employment', 'Shift outside employment period', 'global', 'action_required', "{$name} is not employed on {$date} (employment {$employee['joined_on']} to ".($employee['separated_on'] ?? 'open').'), so a shift cannot be scheduled.');
                }
            }
            $prev = null;
            $weekMinutes = [];
            foreach ($days as $date => $s) {
                $inRange = $date >= $from && $date <= $to;
                if ($s->kind !== 'working' || ! $s->start || ! $s->end) {
                    continue;
                }
                $ctx = $decider->org->at($emp, $date);
                $rules = $engineOn ? $this->compliance->forCountry($ctx['country'] ?? null) : ['rules' => [], 'layers' => []];
                $r = $rules['rules'];
                $layer = fn (string $k) => $rules['layers'][$k] ?? 'country';
                $net = $this->net($s);
                $week = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
                $weekMinutes[$week]['min'] = ($weekMinutes[$week]['min'] ?? 0) + $net;
                $weekMinutes[$week]['rules'] = $r;
                $weekMinutes[$week]['layer'] = $layer('max_weekly_minutes');
                if (! $inRange) {
                    $prev = $s;

                    continue;
                }
                $allowed = $s->shiftRules['allowed_location_ids'] ?? null;
                $location = $ctx['location'] ?? null;
                if (is_array($allowed) && $allowed && $location !== null && ! in_array((int) $location, array_map('intval', $allowed), true)) {
                    $add($emp, $date, 'invalid_location', 'Invalid location assignment', 'global', 'action_required', "Shift {$s->shiftCode} is not allowed at {$name}'s location on {$date}.");
                }
                if (isset($unavailable["$emp|$date"])) {
                    $add($emp, $date, 'unavailable', 'Approved unavailability', 'global', 'action_required', "{$name} has approved unavailability on {$date}: {$unavailable["$emp|$date"]}.");
                }
                if (! empty($r['max_daily_minutes']) && $net > $r['max_daily_minutes']) {
                    $add($emp, $date, 'max_daily_hours', 'Maximum daily work', $layer('max_daily_minutes'), 'warning', "{$name} is scheduled for ".$this->hm($net)." on {$date}, above the configured daily maximum of ".$this->hm($r['max_daily_minutes']).'.');
                }
                $breaks = array_sum(array_map(fn ($seg) => $seg->kind === 'break' ? $seg->minutes() : 0, $s->segments));
                if (! empty($r['break_after_minutes']) && ! empty($r['break_minimum_minutes']) && $s->start->diffInMinutes($s->end) >= $r['break_after_minutes'] && $breaks < $r['break_minimum_minutes']) {
                    $add($emp, $date, 'break_requirement', 'Break requirement', $layer('break_after_minutes'), 'warning', "Shift {$s->shiftCode} on {$date} is ".$this->hm((int) $s->start->diffInMinutes($s->end)).' long but schedules only '.$breaks.' minutes of break (configured minimum '.$r['break_minimum_minutes'].').');
                }
                if ($prev && $prev->date !== $date) {
                    $gap = (int) $prev->end->diffInMinutes($s->start, false);
                    if ($gap < 0) {
                        $add($emp, $date, 'overlap', 'Overlapping shifts', 'global', 'action_required', "{$name}'s shift on {$prev->date} ({$prev->shiftCode}) overlaps the shift on {$date} ({$s->shiftCode}) by ".$this->hm(-$gap).'.');
                    } elseif (! empty($r['min_rest_minutes']) && $gap < $r['min_rest_minutes']) {
                        $add($emp, $date, 'insufficient_rest', 'Minimum rest between shifts', $layer('min_rest_minutes'), 'warning', "{$name} would get only ".$this->hm($gap)." of rest between {$prev->date} and {$date}; the configured minimum is ".$this->hm($r['min_rest_minutes']).'.');
                    }
                }
                $prev = $s;
            }
            foreach ($weekMinutes as $week => $info) {
                $r = $info['rules'];
                $weekEnd = CarbonImmutable::parse($week)->addDays(6)->toDateString();
                if ($weekEnd < $from || $week > $to) {
                    continue;
                }
                $date = max($from, $week);
                if (! empty($r['max_weekly_minutes']) && $info['min'] > $r['max_weekly_minutes']) {
                    $add($emp, $date, 'max_weekly_hours', 'Maximum weekly work', $info['layer'], 'warning', "{$name} is scheduled for ".$this->hm($info['min'])." in the week of {$week}, above the configured weekly maximum of ".$this->hm($r['max_weekly_minutes']).'.');
                }
                if (! empty($r['standard_weekly_minutes']) && ! empty($r['max_weekly_overtime_minutes']) && $info['min'] - $r['standard_weekly_minutes'] > $r['max_weekly_overtime_minutes']) {
                    $add($emp, $date, 'overtime_limit', 'Overtime limit', $info['layer'], 'warning', "Scheduled time for {$name} in the week of {$week} exceeds the standard week by more than the configured overtime limit of ".$this->hm($r['max_weekly_overtime_minutes']).'.');
                }
                if (! empty($r['weekly_rest_minutes'])) {
                    $segs = [];
                    for ($d = CarbonImmutable::parse($week)->subDay(); $d->toDateString() <= $weekEnd; $d = $d->addDay()) {
                        $s = $days[$d->toDateString()] ?? null;
                        if ($s && $s->kind === 'working' && $s->start) {
                            $segs[] = [$s->start, $s->end];
                        }
                    }
                    $ws = TimeZones::localToUtc($week, '00:00', $decider->org->at($emp, $week)['timezone'] ?? 'UTC');
                    $we = $ws->addDays(7);
                    $cursor = $ws;
                    $best = 0;
                    foreach ($segs as [$a, $b]) {
                        if ($a > $cursor) {
                            $best = max($best, (int) $cursor->diffInMinutes(min($a, $we)));
                        }
                        $cursor = max($cursor, $b);
                        if ($cursor >= $we) {
                            break;
                        }
                    }
                    $best = max($best, $cursor < $we ? (int) $cursor->diffInMinutes($we) : 0);
                    if ($best < $r['weekly_rest_minutes'] && $segs) {
                        $add($emp, $date, 'weekly_rest', 'Weekly rest', 'country', 'warning', "{$name} has no uninterrupted rest of ".$this->hm($r['weekly_rest_minutes'])." in the week of {$week} (longest is ".$this->hm($best).').');
                    }
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['employee_id'], $a['date'], $a['code']] <=> [$b['employee_id'], $b['date'], $b['code']]);

        return $out;
    }

    public static function hasBlocking(array $conflicts): bool
    {
        return (bool) array_filter($conflicts, fn ($c) => $c['level'] === 'block');
    }

    private function net(DaySchedule $s): int
    {
        return ShiftCatalog::netMinutes($s->segments);
    }

    /** Count scheduled work minutes overlapping the effective local night window; all interval math is UTC/DST-safe. */
    private function nightOverlapMinutes(DaySchedule $schedule, array $rules): int
    {
        $start = $rules['night_window_start'] ?? null;
        $end = $rules['night_window_end'] ?? null;
        if (! is_string($start) || ! preg_match('/^\d{2}:\d{2}$/', $start) || ! is_string($end) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
            return 0;
        }
        $nextDay = $end <= $start ? 1 : 0;
        $windowStart = TimeZones::localToUtc($schedule->date, $start, $schedule->timezone)->getTimestamp();
        $windowEnd = TimeZones::localToUtc($schedule->date, $end, $schedule->timezone, $nextDay)->getTimestamp();
        $work = [];
        foreach ($schedule->segments as $segment) {
            if ($segment->kind === 'work') {
                $work[] = [$segment->start->getTimestamp(), $segment->end->getTimestamp()];
            }
        }

        return intdiv(Intervals::overlap($work, [[$windowStart, $windowEnd]]), 60);
    }

    private function hm(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.($minutes % 60).'m' : '');
    }
}
