<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/**
 * What the employee was EXPECTED to do on one logical date — the output of the schedule resolver and the
 * schedule input of the calculation engine. All instants are UTC; `date` is the logical attendance date in `timezone`.
 *
 * kind: working | weekly_off | holiday | not_scheduled
 * A half-day holiday keeps kind = working with holidayPortion set and a reduced requiredMinutes.
 */
final class DaySchedule
{
    /** @param array<int,Segment> $segments scheduled work and break segments in order (split shifts have several work segments) */
    public function __construct(
        public readonly int $employeeId,
        public readonly string $date,
        public readonly string $timezone,
        public readonly string $kind,
        public readonly ?int $shiftId = null,
        public readonly ?string $shiftCode = null,
        public readonly ?string $shiftKind = null,            // fixed | flexible | split
        public readonly array $segments = [],
        public readonly ?CarbonImmutable $start = null,
        public readonly ?CarbonImmutable $end = null,
        public readonly int $requiredMinutes = 0,              // net work expected for a full day today
        public readonly ?int $halfDayMinutes = null,
        public readonly ?int $minimumMinutes = null,
        public readonly ?CarbonImmutable $windowStart = null,  // earliest accepted punch for this day
        public readonly ?CarbonImmutable $windowEnd = null,    // latest accepted punch for this day
        public readonly ?CarbonImmutable $flexStartFrom = null,
        public readonly ?CarbonImmutable $flexStartTo = null,
        public readonly ?CarbonImmutable $coreStart = null,
        public readonly ?CarbonImmutable $coreEnd = null,
        public readonly bool $overtimeEligible = true,
        public readonly ?string $holidayName = null,
        public readonly ?string $holidayType = null,           // mandatory | optional | restricted
        public readonly ?string $holidayPortion = null,        // full | first_half | second_half
        public readonly ?int $holidayCalendarId = null,
        public readonly ?int $workWeekId = null,
        public readonly string $source = 'none',               // roster | assignment | rotation | none
        public readonly ?int $rosterEntryId = null,
        public readonly ?int $assignmentId = null,
        public readonly array $shiftRules = [],                // shifts.rules overrides (merged over the policy by the caller)
        public readonly array $notes = [],                     // human-readable resolution notes for the trace
        /** Engine addition (optional): true when start/end/requiredMinutes ALREADY exclude the holiday half; false = the engine shifts start/end itself. */
        public readonly bool $holidayWindowAdjusted = false,
    ) {}

    public function isWorkingDay(): bool
    {
        return $this->kind === 'working';
    }

    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId, 'date' => $this->date, 'timezone' => $this->timezone, 'kind' => $this->kind, 'shift_id' => $this->shiftId, 'shift_code' => $this->shiftCode,
            'shift_kind' => $this->shiftKind, 'start' => $this->start?->utc()->toIso8601String(), 'end' => $this->end?->utc()->toIso8601String(), 'required_minutes' => $this->requiredMinutes,
            'half_day_minutes' => $this->halfDayMinutes, 'holiday' => $this->holidayName, 'holiday_portion' => $this->holidayPortion, 'source' => $this->source, 'roster_entry_id' => $this->rosterEntryId,
            'assignment_id' => $this->assignmentId, 'work_week_id' => $this->workWeekId, 'holiday_calendar_id' => $this->holidayCalendarId, 'notes' => $this->notes,
            'segments' => array_map(fn (Segment $s) => $s->toArray(), $this->segments),
        ];
    }
}
