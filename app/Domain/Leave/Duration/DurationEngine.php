<?php

namespace App\Domain\Leave\Duration;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Engine\DaySchedule;
use App\Domain\Attendance\Requests\LocalInstant;
use App\Domain\Attendance\Scheduling\DatabaseScheduleResolver;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Leave\Contracts\Dto\DurationQuery;
use App\Domain\Leave\Contracts\Dto\DurationResult;
use App\Domain\Leave\Contracts\Dto\DurationSegment;
use App\Domain\Leave\Contracts\DurationCalculator;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Turns "this employee wants leave of this type on these dates / part of a day" into per-day segments and an explainable
 * charge. Side-effect free. The day facts (weekly off, holiday, shift, timezone, employment) come from the Attendance
 * ScheduleResolver (roster -> shift assignment -> work week -> holiday calendar, as of each date); the rules (sandwich, limits,
 * notice, backdating, units) come from the resolved policy version (RuleSet) with tenant settings as the fallback.
 *
 * Units: types whose unit is `hour` are charged in hours, all others in days (half = 0.5, quarter = 0.25, hours/day-minutes).
 * Time is counted as REAL elapsed time, so a clock change inside a requested window is reflected (and flagged).
 */
final class DurationEngine implements DurationCalculator
{
    public const PORTIONS = ['full_day', 'first_half', 'second_half', 'quarter', 'hours'];

    private const BY_UNIT = [
        'day' => ['full_day'],
        'half_day' => ['full_day', 'first_half', 'second_half'],
        'quarter_day' => ['full_day', 'first_half', 'second_half', 'quarter'],
        'hour' => ['full_day', 'first_half', 'second_half', 'quarter', 'hours'],
    ];

    private const NOT_EMPLOYED = '/Not employed yet|Employment ended|pre-boarding|Employment record is archived|Employee record was not found/';

    public function __construct(private ScheduleResolver $schedules) {}

    public function calculate(DurationQuery $q): DurationResult
    {
        $employee = Employee::query()->find($q->employeeId);
        $type = LeaveType::query()->find($q->leaveTypeId);
        if (! $employee || ! $type) {
            return $this->fail(['The employee or leave type was not found.']);
        }
        if (! LocalInstant::validDate($q->startDate) || ! LocalInstant::validDate($q->endDate) || $q->endDate < $q->startDate) {
            return $this->fail(['Choose a valid start date and an end date that is not before it.']);
        }
        $span = (int) CarbonImmutable::parse($q->startDate)->diffInDays(CarbonImmutable::parse($q->endDate)) + 1;
        if ($span > 366) {
            return $this->fail(['A single request cannot cover more than 366 days.']);
        }
        if (! in_array($q->portion, self::PORTIONS, true)) {
            return $this->fail(['Portion must be one of: '.implode(', ', self::PORTIONS).'.']);
        }

        $rules = RuleSet::load($employee->id, $type->id, $q->startDate, $q->policyVersionId);
        $errors = [];
        $warnings = [];
        $explain = [];
        $statutory = $type->is_statutory || $type->category === 'statutory';
        if (! $type->active) {
            $errors[] = $type->name.' is no longer available.';
        }
        if (! $rules->granted) {
            $errors[] = $rules->reason;

            return $this->result([], $errors, $warnings, $explain, $rules, '0.0000', '0.0000');
        }
        $explain[] = $rules->versionId ? $rules->reason : 'No policy version applies; workspace defaults were used.';

        $allowed = (array) ($rules->get('allowed_portions') ?? self::BY_UNIT[$type->unit] ?? self::BY_UNIT['day']);
        if (! in_array($q->portion, $allowed, true)) {
            $errors[] = $type->name.' can only be requested as: '.implode(', ', str_replace('_', ' ', $allowed)).'.';
        }
        $hourType = $type->unit === 'hour';
        $calendarDays = ($rules->get('day_counting') === 'calendar') || (bool) ($type->config['calendar_days'] ?? false);
        $units = LeaveSettings::get('units');
        $defaultDay = (int) $units['minutes_per_day'];

        $sandwich = $rules->sandwich();
        $sandwichOn = $sandwich['enabled'] && $this->sandwichApplies($sandwich['apply_to'], $type) && $q->portion === 'full_day' && ! $calendarDays;
        $pad = $sandwichOn ? 14 : 0;
        $from = CarbonImmutable::parse($q->startDate)->subDays($pad)->toDateString();
        $to = CarbonImmutable::parse($q->endDate)->addDays($pad)->toDateString();
        $days = $this->schedules->resolveRange($employee->id, $from, $to);
        $zone = $q->timezone ?: ($days[$q->startDate]->timezone ?? null) ?: ($employee->timezone ?: 'UTC');
        if (! TimeZones::isValid($zone)) {
            $zone = 'UTC';
        }

        $segments = [];   // "date|portion" => segment array
        $payClass = $this->payClass($type, $rules);
        $partial = $q->portion !== 'full_day';

        if ($q->portion === 'hours') {
            $this->hours($q, $employee, $type, $days, $zone, $defaultDay, $hourType, $payClass, $segments, $errors, $explain, $calendarDays);
        } else {
            foreach (DatabaseScheduleResolver::dates($q->startDate, $q->endDate) as $date) {
                $info = $this->classify($days[$date] ?? null, $type, $defaultDay, $units);
                if ($info['kind'] === 'outside') {
                    $errors[] = "$date: ".$info['note'];

                    continue;
                }
                if ($partial && $q->startDate !== $q->endDate) {
                    $errors[] = ucfirst(str_replace('_', ' ', $q->portion)).' leave covers one day: set the end date equal to the start date.';
                    break;
                }
                $segments[$date.'|'.$q->portion] = $this->daySegment($date, $q->portion, $info, $hourType, $calendarDays, $payClass, $units, $days[$date] ?? null);
            }
            if ($partial && $q->startDate === $q->endDate) {
                $only = $segments[$q->startDate.'|'.$q->portion] ?? null;
                if ($only && ! $only['charged'] && in_array($only['dayKind'], ['weekly_off', 'holiday'], true) && ! $calendarDays) {
                    $errors[] = $q->startDate.' is not a working day for this employee ('.$only['explanation'].'); there is nothing to take leave against.';
                }
            }
        }

        if ($type->kind === 'optional_holiday') {
            foreach (array_keys($segments) as $key) {
                $date = explode('|', $key)[0];
                if (! in_array(($days[$date] ?? null)?->holidayType, ['optional', 'restricted'], true)) {
                    $errors[] = "$date is not an optional holiday in the employee's holiday calendar.";
                }
            }
        }

        if ($sandwichOn && ! $errors) {
            $this->sandwich($q, $employee, $type, $days, $sandwich, $segments, $explain, $payClass, $defaultDay, $hourType, $units);
        }

        ksort($segments);
        $charged = Units::sum(array_map(fn ($s) => $s['charged'] ? $s['units'] : '0', $segments));
        $calendar = $this->calendarUnits($q, $segments, $defaultDay, $hourType, $span);

        // ---- limits, notice, backdating (statutory entitlements are flagged, never blindly denied) ---------
        $violate = function (string $kind, string $message, array $detail = []) use ($statutory, &$errors, &$warnings) {
            if ($statutory) {
                $warnings[] = ['kind' => $kind, 'severity' => 'escalate', 'message' => $message.' This is statutory leave, so it is routed for review instead of being refused.', 'detail' => $detail];
            } else {
                $errors[] = $message;
            }
        };
        $chargedDates = array_values(array_unique(array_map(fn ($s) => $s['date'], array_filter($segments, fn ($s) => $s['charged']))));
        sort($chargedDates);
        $consecutive = $chargedDates ? (int) CarbonImmutable::parse($chargedDates[0])->diffInDays(CarbonImmutable::parse(end($chargedDates))) + 1 : 0;
        if (! $errors && Units::isZero($charged)) {
            $errors[] = 'Nothing to request: every selected day is a holiday or weekly off, so no leave would be used.';
        }
        $label = $hourType ? 'hours' : 'days';
        if (! $errors || $statutory) {
            if (($min = $rules->get('min_units')) !== null && Units::cmp($charged, $min) < 0) {
                $violate('min_units', "The minimum for {$type->name} is ".self::trim($min)." $label per request (this request is ".self::trim($charged).').', ['min' => Units::normalize($min)]);
            }
            if (($max = $rules->get('max_units')) !== null && Units::cmp($charged, $max) > 0) {
                $violate('max_units', "The maximum for {$type->name} is ".self::trim($max)." $label per request (this request is ".self::trim($charged).').', ['max' => Units::normalize($max)]);
            }
            if (($max = $rules->get('max_consecutive')) !== null && $consecutive > (int) $max) {
                $violate('max_consecutive', "{$type->name} cannot run for more than $max consecutive days (this request spans $consecutive).", ['max' => (int) $max, 'span' => $consecutive]);
            }
            if (($min = $rules->get('min_consecutive')) !== null && $consecutive < (int) $min) {
                $violate('min_consecutive', "{$type->name} must be taken for at least $min consecutive days (this request spans $consecutive).", ['min' => (int) $min, 'span' => $consecutive]);
            }
        }
        $today = $q->submittedOn ?: TenantClock::today($employee->timezone ?: null);
        $ahead = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($q->startDate), false);
        $notice = (int) $rules->get('notice_days', 'requests.advance_notice_days', 0);
        $backdate = (int) $rules->get('backdate_days', 'requests.backdate_days', 0);
        $future = (int) $rules->get('future_days', 'requests.future_days', 0);
        if ($ahead >= 0 && $notice > 0 && $ahead < $notice) {
            $violate('notice', "{$type->name} needs $notice day(s) notice; this starts in $ahead day(s).", ['notice_days' => $notice, 'days_ahead' => $ahead]);
        }
        if ($ahead < 0 && -$ahead > $backdate) {
            $violate('backdate', "Leave can be requested at most $backdate day(s) after the fact; this starts ".(-$ahead).' day(s) ago.', ['backdate_days' => $backdate]);
        } elseif ($ahead < 0) {
            $warnings[] = ['kind' => 'backdated', 'severity' => 'info', 'message' => 'This leave starts in the past ('.(-$ahead).' day(s) ago) and is backdated.', 'detail' => ['days' => -$ahead]];
        }
        if ($future > 0 && $ahead > $future) {
            $violate('future', "Leave can be requested at most $future day(s) ahead; this starts in $ahead days.", ['future_days' => $future]);
        }
        if ($partial && TimeZones::dstChangesBetween($zone, CarbonImmutable::parse($q->startDate)->toDateString(), CarbonImmutable::parse($q->endDate)->addDay()->toDateString()) || TimeZones::dstChangesBetween($zone, CarbonImmutable::parse($q->startDate)->subDay()->toDateString(), $q->startDate)) {
            $warnings[] = ['kind' => 'clock_change', 'severity' => 'info', 'message' => 'A clock change happens around these dates; the time is counted as real elapsed time.', 'detail' => ['timezone' => $zone]];
        }
        $explain[] = 'Total charge: '.self::trim($charged).' '.$label.($sandwichOn ? ' (sandwich rule evaluated)' : '').'.';

        return $this->result(array_values($segments), $errors, $warnings, $explain, $rules, $charged, $calendar);
    }

    // ---------------------------------------------------------------------------------------------------------

    /** @return array{kind:string,minutes:int,half:int,note:string,holiday:?string,holiday_portion:?string,holiday_type:?string} */
    private function classify(?DaySchedule $s, LeaveType $type, int $defaultDay, array $units): array
    {
        $out = ['kind' => 'workday', 'minutes' => $defaultDay, 'half' => (int) $units['minutes_per_half_day'], 'note' => 'Working day.', 'holiday' => null, 'holiday_portion' => null, 'holiday_type' => null];
        if (! $s) {
            return ['kind' => 'outside', 'note' => 'No schedule information is available.'] + $out;
        }
        $out['holiday'] = $s->holidayName;
        $out['holiday_portion'] = $s->holidayPortion;
        $out['holiday_type'] = $s->holidayType;
        if ($s->kind === 'working') {
            if ($s->requiredMinutes > 0 && ! in_array($s->holidayPortion, ['first_half', 'second_half'], true)) {
                $out['minutes'] = $s->requiredMinutes;
                $out['half'] = $s->halfDayMinutes ?: intdiv($s->requiredMinutes, 2);
            }
            $out['note'] = $s->shiftCode ? "Working day (shift {$s->shiftCode})." : 'Working day.';

            return $out;
        }
        if ($s->kind === 'weekly_off') {
            return ['kind' => 'weekly_off', 'note' => 'Weekly off.'] + $out;
        }
        if ($s->kind === 'holiday') {
            if (in_array($s->holidayType, ['optional', 'restricted'], true) && $type->kind !== 'optional_holiday') {
                $out['note'] = "Working day ({$s->holidayName} is an optional holiday).";

                return $out;
            }

            return ['kind' => 'holiday', 'note' => 'Holiday: '.($s->holidayName ?? 'public holiday').'.'] + $out;
        }
        $notEmployed = array_values(array_filter($s->notes, fn ($n) => preg_match(self::NOT_EMPLOYED, $n)));
        if ($notEmployed) {
            return ['kind' => 'outside', 'note' => implode(' ', $notEmployed)] + $out;
        }

        return ['note' => 'Working day (no shift assigned; workspace default hours).'] + $out;
    }

    private function unitsFor(string $portion, array $info, bool $hourType, ?int $minutes = null): string
    {
        $dayMinutes = max(1, $info['minutes']);
        if ($portion === 'hours') {
            return $hourType ? Units::div((string) $minutes, '60') : Units::div((string) $minutes, (string) $dayMinutes);
        }
        $fraction = ['full_day' => '1', 'first_half' => '0.5', 'second_half' => '0.5', 'quarter' => '0.25'][$portion];
        if (! $hourType) {
            return Units::normalize($fraction);
        }

        return Units::div(Units::mul((string) $dayMinutes, $fraction), '60');
    }

    private function minutesFor(string $portion, array $info, array $units): int
    {
        return match ($portion) {
            'first_half', 'second_half' => $info['half'],
            'quarter' => (int) $units['minutes_per_quarter_day'],
            default => $info['minutes'],
        };
    }

    /** One whole-day or fixed-portion segment for a date. */
    private function daySegment(string $date, string $portion, array $info, bool $hourType, bool $calendarDays, string $payClass, array $units, ?DaySchedule $schedule): array
    {
        $base = ['date' => $date, 'portion' => $portion, 'payClass' => $payClass, 'startsAt' => null, 'endsAt' => null,
            'detail' => array_filter(['shift_id' => $schedule?->shiftId, 'shift' => $schedule?->shiftCode, 'holiday' => $info['holiday'], 'holiday_portion' => $info['holiday_portion'], 'source' => $schedule?->source], fn ($v) => $v !== null)];
        if (in_array($info['kind'], ['holiday', 'weekly_off'], true)) {
            if ($calendarDays) {
                return $base + ['dayKind' => $info['kind'], 'charged' => true, 'minutes' => $info['minutes'], 'units' => $this->unitsFor('full_day', $info, $hourType),
                    'explanation' => $info['note'].' Counted because this leave type counts calendar days.'];
            }

            return $base + ['dayKind' => $info['kind'], 'charged' => false, 'minutes' => 0, 'units' => '0.0000', 'explanation' => $info['note'].' Not charged.'];
        }
        $half = $info['holiday_portion'];
        $why = $info['note'];
        $effective = $portion;
        $dayKind = $portion === 'full_day' ? 'workday' : 'partial';
        if (in_array($half, ['first_half', 'second_half'], true)) {
            if ($portion === 'full_day') {
                $effective = $half === 'first_half' ? 'second_half' : 'first_half';
                $dayKind = 'partial';
                $why = "{$info['holiday']} is a half-day holiday ($half), so only the other half is charged.";
            } elseif ($portion === $half) {
                return $base + ['dayKind' => 'holiday', 'charged' => false, 'minutes' => 0, 'units' => '0.0000', 'explanation' => "That half of the day is already the {$info['holiday']} holiday. Not charged."];
            }
        }
        $minutes = $this->minutesFor($effective, $info, $units);
        $startsAt = $endsAt = null;
        if ($schedule && $schedule->start && $schedule->end && in_array($effective, ['first_half', 'second_half'], true)) {
            $startsAt = $effective === 'first_half' ? $schedule->start : $schedule->end->subMinutes($minutes);
            $endsAt = $effective === 'first_half' ? $schedule->start->addMinutes($minutes) : $schedule->end;
        } elseif ($schedule && $schedule->start && $schedule->end && $effective === 'full_day') {
            [$startsAt, $endsAt] = [$schedule->start, $schedule->end];
        }
        $charge = $this->unitsFor($effective, $info, $hourType);

        return $base + ['dayKind' => $dayKind, 'charged' => true, 'minutes' => $minutes, 'units' => $charge,
            'explanation' => $why.' Charged '.self::trim($charge).($hourType ? ' hour(s).' : ' day(s).'),
            'startsAt' => $startsAt?->utc()->format('Y-m-d H:i:s'), 'endsAt' => $endsAt?->utc()->format('Y-m-d H:i:s')];
    }

    /** Hourly leave: real elapsed minutes between two local wall-clock times, split at local midnight unless one night shift owns both parts. */
    private function hours(DurationQuery $q, Employee $employee, LeaveType $type, array $days, string $zone, int $defaultDay, bool $hourType, string $payClass, array &$segments, array &$errors, array &$explain, bool $calendarDays): void
    {
        if ($q->startDate !== $q->endDate) {
            $errors[] = 'Hourly leave covers one day: set the end date equal to the start date.';

            return;
        }
        if (! $q->startTime || ! $q->endTime) {
            $errors[] = 'Hourly leave needs a start time and an end time.';

            return;
        }
        try {
            $start = LocalInstant::toUtc($q->startTime, $q->startDate, $zone, 'start_time');
            $end = LocalInstant::toUtc($q->endTime, $q->startDate, $zone, 'end_time');
        } catch (ValidationException) {
            $errors[] = 'Enter the times as HH:MM.';

            return;
        }
        if ($end <= $start) {
            $end = TimeZones::localToUtc($q->startDate, $q->endTime, $zone, 1);
            $explain[] = 'The end time is earlier than the start time, so the leave runs past midnight into the next day.';
        }
        $minutes = (int) $start->diffInMinutes($end);
        if ($minutes < 1 || $minutes > 1440) {
            $errors[] = 'Hourly leave must be between 1 minute and 24 hours.';

            return;
        }
        $schedule = $days[$q->startDate] ?? null;
        $nightShift = $schedule && $schedule->kind === 'working' && $schedule->end && TimeZones::localDate($schedule->end, $zone) > $q->startDate;
        $midnight = TimeZones::localToUtc($q->startDate, '00:00', $zone, 1);
        $parts = (! $nightShift && $end > $midnight && $start < $midnight)
            ? [[$q->startDate, $start, $midnight], [CarbonImmutable::parse($q->startDate)->addDay()->toDateString(), $midnight, $end]]
            : [[$q->startDate, $start, $end]];
        if ($nightShift) {
            $explain[] = "The hours fall inside the night shift that belongs to $q->startDate, so they are recorded on that logical date.";
        }
        $units = LeaveSettings::get('units');
        foreach ($parts as [$date, $s, $e]) {
            $info = $this->classify($days[$date] ?? $this->schedules->resolve($employee->id, $date), $type, $defaultDay, $units);
            $mins = (int) $s->diffInMinutes($e);
            if ($info['kind'] === 'outside') {
                $errors[] = "$date: ".$info['note'];

                continue;
            }
            $charged = ! in_array($info['kind'], ['holiday', 'weekly_off'], true) || $calendarDays;
            $segments[$date.'|hours'] = ['date' => $date, 'portion' => 'hours', 'minutes' => $mins, 'units' => $charged ? $this->unitsFor('hours', $info, $hourType, $mins) : '0.0000', 'charged' => $charged,
                'dayKind' => $charged ? ($info['kind'] === 'workday' ? 'partial' : $info['kind']) : $info['kind'], 'payClass' => $payClass,
                'explanation' => $charged ? "$mins minute(s) of real elapsed time ({$s->setTimezone($zone)->format('H:i')} to {$e->setTimezone($zone)->format('H:i')} local)." : $info['note'].' Not charged.',
                'startsAt' => $s->utc()->format('Y-m-d H:i:s'), 'endsAt' => $e->utc()->format('Y-m-d H:i:s'), 'detail' => ['timezone' => $zone]];
        }
        if (count($parts) > 1) {
            $explain[] = 'The hours cross midnight and are split into two days.';
        }
    }

    /** Non-working days sandwiched between leave days become leave days too (inside the request and against neighbouring leave). */
    private function sandwich(DurationQuery $q, Employee $employee, LeaveType $type, array $days, array $rule, array &$segments, array &$explain, string $payClass, int $defaultDay, bool $hourType, array $units): void
    {
        $workDates = array_map(fn ($k) => explode('|', $k)[0], array_keys(array_filter($segments, fn ($s) => $s['charged'] && in_array($s['dayKind'], ['workday', 'partial'], true))));
        if (! $workDates) {
            return;
        }
        sort($workDates);
        $first = $workDates[0];
        $last = end($workDates);
        $make = function (string $date, string $why) use ($days, $payClass, $hourType, $rule, $type, $defaultDay, $units, &$segments, &$explain) {
            $info = $this->classify($days[$date] ?? null, $type, $defaultDay, $units);
            if (! in_array($info['kind'], ['holiday', 'weekly_off'], true)) {
                return;
            }
            if (($info['kind'] === 'holiday' && ! $rule['count_holidays']) || ($info['kind'] === 'weekly_off' && ! $rule['count_weekly_offs'])) {
                return;
            }
            $segments[$date.'|full_day'] = ['date' => $date, 'portion' => 'full_day', 'minutes' => $info['minutes'], 'units' => $this->unitsFor('full_day', $info, $hourType), 'charged' => true,
                'dayKind' => 'sandwiched', 'payClass' => $payClass, 'explanation' => "{$info['note']} Counted as leave: $why (sandwich rule).", 'startsAt' => null, 'endsAt' => null,
                'detail' => ['sandwich' => true, 'natural_kind' => $info['kind']]];
            $explain[] = "$date ({$info['kind']}) is counted because $why.";
        };
        foreach (array_keys($segments) as $key) {
            $date = explode('|', $key)[0];
            if ($date > $first && $date < $last && ! $segments[$key]['charged'] && in_array($segments[$key]['dayKind'], ['holiday', 'weekly_off'], true)) {
                $make($date, 'it lies between two leave days of this request');
            }
        }
        foreach ([[-1, $first], [1, $last]] as [$direction, $edge]) {
            $gap = [];
            $cursor = CarbonImmutable::parse($edge)->addDays($direction);
            for ($i = 0; $i < 14; $i++, $cursor = $cursor->addDays($direction)) {
                $date = $cursor->toDateString();
                $info = $this->classify($days[$date] ?? null, $type, $defaultDay, $units);
                if (in_array($info['kind'], ['holiday', 'weekly_off'], true)) {
                    $gap[] = $date;

                    continue;
                }
                if ($info['kind'] === 'workday' && $gap && $this->hasLeaveOn($employee->id, $date, $q->ignoreRequestId)) {
                    foreach ($gap as $g) {
                        if (! isset($segments[$g.'|full_day']) || ! $segments[$g.'|full_day']['charged']) {
                            $make($g, "you also have leave on $date");
                        }
                    }
                }
                break;
            }
        }
    }

    private function hasLeaveOn(int $employeeId, string $date, ?int $ignoreRequestId): bool
    {
        return LeaveRequestSegment::query()->where('employee_id', $employeeId)->where('leave_date', $date)->where('status', 'active')->where('charged', true)
            ->whereIn('request_id', fn ($q) => $q->select('id')->from('leave_requests')->whereIn('status', ['pending', 'approved', 'partially_cancelled'])
                ->when($ignoreRequestId, fn ($qq) => $qq->where('id', '!=', $ignoreRequestId)))->exists();
    }

    private function sandwichApplies(array $applyTo, LeaveType $type): bool
    {
        if (! $applyTo) {
            return true;
        }
        foreach ($applyTo as $x) {
            if ((string) $x === (string) $type->id || (string) $x === $type->code || (string) $x === $type->kind || (string) $x === $type->uuid) {
                return true;
            }
        }

        return false;
    }

    private function calendarUnits(DurationQuery $q, array $segments, int $defaultDay, bool $hourType, int $span): string
    {
        if ($q->portion !== 'full_day') {
            return Units::sum(array_map(fn ($s) => $s['units'], $segments));
        }
        $perDay = $hourType ? Units::div((string) $defaultDay, '60') : '1.0000';

        return Units::mul($perDay, (string) $span);
    }

    private function payClass(LeaveType $type, RuleSet $rules): string
    {
        $configured = $rules->get('pay_class');
        if (in_array($configured, ['paid', 'unpaid', 'statutory', 'lop', 'comp_off'], true)) {
            return $configured;
        }
        if ($type->kind === 'comp_off') {
            return 'comp_off';
        }

        return match ($type->category) {
            'unpaid' => 'unpaid',
            'statutory' => 'statutory',
            default => 'paid',
        };
    }

    private function result(array $segments, array $errors, array $warnings, array $explain, RuleSet $rules, string $charged, string $calendar): DurationResult
    {
        $dto = array_map(fn ($s) => new DurationSegment($s['date'], $s['portion'], (int) $s['minutes'], Units::normalize($s['units']), (bool) $s['charged'], $s['dayKind'], $s['payClass'], $s['explanation'], $s['startsAt'], $s['endsAt'], $s['detail']), $segments);

        return new DurationResult($dto, Units::normalize($charged), Units::normalize($calendar), $explain, $warnings, $rules->versionId, ! $errors, array_values(array_unique($errors)));
    }

    private function fail(array $errors): DurationResult
    {
        return new DurationResult([], '0.0000', '0.0000', [], [], null, false, $errors);
    }

    private static function trim(string|int|float|null $units): string
    {
        $v = Units::normalize($units);

        return rtrim(rtrim($v, '0'), '.') ?: '0';
    }
}
