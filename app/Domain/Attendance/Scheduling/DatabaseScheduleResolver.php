<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Engine\DaySchedule;
use Carbon\CarbonImmutable;

/**
 * Implements the ScheduleResolver contract on top of ScheduleDecider (batch-loaded) and ShiftCatalog (DST-safe instants).
 * resolve()/resolveRange() cost a fixed number of queries; resolveMany() does the same for many employees at once.
 */
final class DatabaseScheduleResolver implements ScheduleResolver
{
    public function resolve(int $employeeId, string $date): DaySchedule
    {
        return $this->resolveRange($employeeId, $date, $date)[$date];
    }

    public function resolveRange(int $employeeId, string $from, string $to): array
    {
        return $this->resolveMany([$employeeId], $from, $to)[$employeeId] ?? [];
    }

    /** @param array<int,int> $employeeIds @return array<int,array<string,DaySchedule>> employee id => date => schedule */
    public function resolveMany(array $employeeIds, string $from, string $to, array $overlay = []): array
    {
        $decider = ScheduleDecider::load($employeeIds, $from, $to, $overlay);
        $out = [];
        foreach (array_unique($employeeIds) as $id) {
            foreach (self::dates($from, $to) as $date) {
                $out[$id][$date] = $this->materialize($decider->decide((int) $id, $date));
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    public static function dates(string $from, string $to): array
    {
        $dates = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /** Turns a decision into the contract object (UTC segments, windows, required minutes). */
    public function materialize(array $d): DaySchedule
    {
        $holiday = $d['holiday'];
        $common = ['employeeId' => $d['employee_id'], 'date' => $d['date'], 'timezone' => $d['timezone'], 'holidayName' => $holiday['name'] ?? null, 'holidayType' => $holiday['type'] ?? null,
            'holidayPortion' => $holiday['portion'] ?? null, 'holidayCalendarId' => $holiday['calendar_id'] ?? null, 'workWeekId' => $d['work_week']['id'] ?? null,
            'source' => $d['source'], 'rosterEntryId' => $d['roster_entry']['id'] ?? null, 'assignmentId' => $d['assignment_id'], 'notes' => $d['notes']];
        if ($d['kind'] !== 'working' || ! $d['shift']) {
            return new DaySchedule(...$common, kind: $d['kind']);
        }
        $shift = $d['shift'];
        $zone = $shift['timezone'];
        $date = $d['date'];
        $segments = ShiftCatalog::segments($shift, $date);
        $work = array_values(array_filter($segments, fn ($s) => $s->kind === 'work'));
        $start = $work ? min(array_map(fn ($s) => $s->start, $work)) : null;
        $end = $work ? max(array_map(fn ($s) => $s->end, $work)) : null;
        $net = ShiftCatalog::netMinutes($segments);
        $required = $shift['required_minutes'] ?: ($shift['full_day_minutes'] ?: $net);
        $notes = $d['notes'];
        $workDay = $d['work_day'];
        if ($d['source'] === 'assignment' && $workDay) { // explicit roster/rotation days are not reshaped by the work week
            if ($workDay['type'] === 'half') {
                $required = $workDay['minutes'] ?: ($shift['half_day_minutes'] ?: intdiv($required, 2));
                $notes[] = "Required work reduced to {$required} minutes because the work week marks this weekday as a half day.";
            } elseif ($workDay['type'] === 'custom' && $workDay['minutes']) {
                $required = (int) $workDay['minutes'];
                $notes[] = "Required work set to {$required} minutes by the work week's custom day.";
            }
        }
        if ($holiday && $holiday['portion'] !== 'full') {
            $required = min($required, $shift['half_day_minutes'] ?: intdiv($required, 2));
            $notes[] = "Half-day holiday: required work reduced to {$required} minutes.";
        }
        $before = (int) $shift['punch_window_before'];
        $after = (int) $shift['punch_window_after'];
        $flexFrom = $flexTo = $coreStart = $coreEnd = null;
        $windowStart = $start?->subMinutes($before);
        $windowEnd = $end?->addMinutes($after);
        if ($shift['kind'] === 'flexible') {
            $flexFrom = ShiftCatalog::instant($date, $shift['flex_start_from'], $zone);
            $flexTo = ShiftCatalog::instant($date, $shift['flex_start_to'], $zone, $shift['flex_start_from']);
            $coreStart = ShiftCatalog::instant($date, $shift['core_start'], $zone);
            $coreEnd = ShiftCatalog::instant($date, $shift['core_end'], $zone, $shift['core_start']);
            $unpaid = array_sum(array_map(fn ($s) => $s->kind === 'break' && ! $s->paid ? $s->minutes() : 0, $segments));
            if ($flexFrom) {
                $start = $flexFrom;
                $windowStart = $flexFrom->subMinutes($before);
            }
            if ($flexTo) {
                $latestEnd = $flexTo->addMinutes($required + $unpaid);
                $end = $end && $end > $latestEnd ? $end : $latestEnd;
                $windowEnd = $end->addMinutes($after);
            }
        }

        return new DaySchedule(...array_merge($common, ['kind' => 'working', 'shiftId' => (int) $shift['id'], 'shiftCode' => $shift['code'], 'shiftKind' => $shift['kind'], 'segments' => $segments,
            'start' => $start, 'end' => $end, 'requiredMinutes' => (int) $required, 'halfDayMinutes' => $shift['half_day_minutes'] !== null ? (int) $shift['half_day_minutes'] : null,
            'minimumMinutes' => $shift['minimum_minutes'] !== null ? (int) $shift['minimum_minutes'] : null, 'windowStart' => $windowStart, 'windowEnd' => $windowEnd,
            'flexStartFrom' => $flexFrom, 'flexStartTo' => $flexTo, 'coreStart' => $coreStart, 'coreEnd' => $coreEnd, 'overtimeEligible' => (bool) $shift['overtime_eligible'],
            'shiftRules' => $shift['rules'], 'notes' => $notes]));
    }
}
