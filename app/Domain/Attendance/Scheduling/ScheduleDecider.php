<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Batch-loaded, in-memory schedule decision for many employees over a date range. load() runs a FIXED number of queries
 * (independent of the number of employees); decide() is pure memory work, so a 200-employee roster grid or a 31-day range
 * stays cheap. It produces a plain decision array; DatabaseScheduleResolver turns it into the DaySchedule contract object.
 *
 * Resolution order (recorded in notes): employment period -> published roster entry -> shift assignment / rotation ->
 * work week (weekly off, half day, custom minutes) -> holiday calendar.
 *
 * @phpstan-type Decision array{employee_id:int,date:string,timezone:string,kind:string,source:string,shift:?array,roster_entry:?array,assignment_id:?int,work_week:?array,work_day:?array,holiday:?array,notes:array<int,string>,day_index:?int,ambiguous:bool}
 */
final class ScheduleDecider
{
    public OrgContext $org;

    /** @var array<int,array<string,array<string,mixed>>> */
    private array $rosterEntries = [];

    /** @var array<int,array<string,array<string,mixed>>> */
    private array $overlay = [];

    /** @var array<int,array<string,mixed>> */
    private array $shifts = [];

    private array $shiftAssignments = [];

    private array $workWeekAssignments = [];

    private array $holidayAssignments = [];

    /** @var array<int,array<int,array<string,mixed>>> rotation id => day_index => pattern row */
    private array $patterns = [];

    private array $rotations = [];

    /** @var array<string,array<int,array<string,mixed>>> work week code => versions (with 'days' keyed by weekday) */
    private array $workWeeks = [];

    private array $workWeekById = [];

    private array $calendars = [];

    /** @var array<int,array<string,array<string,mixed>>> calendar id => date => holiday */
    private array $holidays = [];

    private array $defaultWorkingDays = [1, 2, 3, 4, 5];

    private array $names = [];

    private string $tenantZone = 'UTC';

    /**
     * @param  array<int,int>  $employeeIds
     * @param  array<int,array<string,array{entry_type:string,shift_id:?int}>>  $overlay  proposed entries (employee => date => entry) that take precedence over published roster rows
     */
    public static function load(array $employeeIds, string $from, string $to, array $overlay = []): self
    {
        $self = new self;
        $tenant = app(TenantContext::class)->id;
        $self->org = OrgContext::load($employeeIds, $from, $to);
        $self->overlay = $overlay;
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        $settings = json_decode((string) DB::table('tenants')->where('id', $tenant)->value('settings'), true) ?: [];
        $self->defaultWorkingDays = array_map('intval', $settings['work_week'] ?? [1, 2, 3, 4, 5]);
        $self->tenantZone = $settings['timezone'] ?? config('app.timezone');

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('roster_entries')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)->where('status', 'published')
                ->whereBetween('work_date', [$from, $to])->orderBy('published_at')->orderBy('id')->get();
            foreach ($rows as $row) {
                $self->rosterEntries[$row->employee_id][$row->work_date] = (array) $row; // latest published wins (ordered ascending)
            }
        }

        $targets = $self->org->targets();
        $self->shiftAssignments = AssignmentResolver::fetch('shift_assignments', $from, $to, $targets);
        $self->workWeekAssignments = AssignmentResolver::fetch('work_week_assignments', $from, $to, $targets);
        $self->holidayAssignments = AssignmentResolver::fetch('employee_holiday_calendar_assignments', $from, $to, $targets);

        // Rotations and their patterns.
        $rotationIds = array_values(array_unique(array_filter(array_column($self->shiftAssignments, 'rotation_id'))));
        $shiftIds = array_filter(array_column($self->shiftAssignments, 'shift_id'));
        if ($rotationIds) {
            foreach (DB::table('shift_rotations')->where('tenant_id', $tenant)->whereIn('id', $rotationIds)->get() as $r) {
                $self->rotations[$r->id] = (array) $r;
            }
            foreach (DB::table('shift_rotation_patterns')->where('tenant_id', $tenant)->whereIn('rotation_id', $rotationIds)->get() as $p) {
                $self->patterns[$p->rotation_id][$p->day_index] = (array) $p;
                $shiftIds[] = $p->shift_id;
            }
        }
        foreach ($self->rosterEntries as $dates) {
            foreach ($dates as $entry) {
                $shiftIds[] = $entry['shift_id'];
            }
        }
        foreach ($overlay as $dates) {
            foreach ($dates as $entry) {
                $shiftIds[] = $entry['shift_id'] ?? null;
            }
        }
        $self->shifts = ShiftCatalog::load($shiftIds);

        // Work weeks: every version of every assigned week code.
        $weekIds = array_values(array_unique(array_column($self->workWeekAssignments, 'work_week_id')));
        if ($weekIds) {
            $codes = DB::table('work_weeks')->where('tenant_id', $tenant)->whereIn('id', $weekIds)->pluck('code')->all();
            $versions = DB::table('work_weeks')->where('tenant_id', $tenant)->whereIn('code', $codes)->get();
            $days = DB::table('work_week_days')->where('tenant_id', $tenant)->whereIn('work_week_id', $versions->pluck('id'))->get()->groupBy('work_week_id');
            foreach ($versions as $v) {
                $v = (array) $v;
                $v['days'] = [];
                foreach ($days->get($v['id'], []) as $d) {
                    $v['days'][(int) $d->weekday] = (array) $d;
                }
                $self->workWeeks[$v['code']][] = $v;
                $self->workWeekById[$v['id']] = $v;
            }
        }

        // Holiday calendars.
        $calendarIds = array_values(array_unique(array_column($self->holidayAssignments, 'calendar_id')));
        if ($calendarIds) {
            foreach (DB::table('holiday_calendars')->where('tenant_id', $tenant)->whereIn('id', $calendarIds)->get() as $c) {
                $self->calendars[$c->id] = (array) $c;
            }
            foreach (DB::table('holidays')->where('tenant_id', $tenant)->whereIn('calendar_id', $calendarIds)->whereBetween('date', [$from, $to])->get() as $h) {
                $self->holidays[$h->calendar_id][$h->date] = (array) $h;
            }
        }

        return $self;
    }

    public function shift(int $id): ?array
    {
        return $this->shifts[$id] ?? null;
    }

    /** @return array<string,mixed> a decision (see class docblock) */
    public function decide(int $employeeId, string $date): array
    {
        $employee = $this->org->employee($employeeId);
        $notes = [];
        $base = ['employee_id' => $employeeId, 'date' => $date, 'timezone' => $employee['timezone'] ?? $this->tenantZone, 'kind' => 'not_scheduled', 'source' => 'none', 'shift' => null,
            'roster_entry' => null, 'assignment_id' => null, 'work_week' => null, 'work_day' => null, 'holiday' => null, 'notes' => [], 'day_index' => null, 'ambiguous' => false];
        if (! $employee) {
            $base['notes'] = ['Employee record was not found.'];

            return $base;
        }
        // (1) employment period
        if ($employee['status'] === 'preboarding' && $date <= \App\Domain\Tenancy\TenantClock::today()) {
            $base['notes'] = ['Employee is still pre-boarding; no schedule applies until they start.'];

            return $base;
        }
        if ($employee['status'] === 'archived' && ! $employee['separated_on']) {
            $base['notes'] = ['Employment record is archived.'];

            return $base;
        }
        if ($date < $employee['joined_on']) {
            $base['notes'] = ["Not employed yet: joins on {$employee['joined_on']}."];

            return $base;
        }
        if ($employee['separated_on'] && $date > $employee['separated_on']) {
            $base['notes'] = ["Employment ended on {$employee['separated_on']}."];

            return $base;
        }
        $ctx = $this->org->at($employeeId, $date);
        $weekday = (int) CarbonImmutable::parse($date)->isoWeekday();
        $shift = null;
        $source = 'none';
        $kind = null;
        $rosterRow = null;
        $assignmentId = null;
        $dayIndex = null;
        $ambiguous = false;

        // (2) published roster entry (a proposed overlay entry replaces the published one when validating drafts)
        $entry = $this->overlay[$employeeId][$date] ?? $this->rosterEntries[$employeeId][$date] ?? null;
        if ($entry) {
            $rosterRow = $entry;
            if ($entry['entry_type'] === 'shift' && ($entry['shift_id'] ?? null) && isset($this->shifts[$entry['shift_id']])) {
                $shift = $this->shifts[$entry['shift_id']];
                $source = 'roster';
                $kind = 'working';
                $notes[] = "Shift {$shift['code']} from the published roster".(! empty($entry['previous_entry_id']) ? ' (changed after first publication)' : '').'.';
            } elseif ($entry['entry_type'] === 'off') {
                $source = 'roster';
                $kind = 'weekly_off';
                $notes[] = 'Day off from the published roster.';
            } else {
                $notes[] = 'Roster entry is cleared; falling back to shift assignments.';
                $rosterRow = null;
            }
        }

        // (3)/(4) assignment or rotation
        if ($kind === null) {
            $pick = AssignmentResolver::pick($this->shiftAssignments, $ctx, $date);
            if ($pick) {
                $row = $pick['row'];
                $assignmentId = (int) $row['id'];
                $ambiguous = $pick['ambiguous'];
                $why = AssignmentResolver::describe($row, $this->targetName($row['target_type'], $row['target_id'], $ctx));
                if ($ambiguous) {
                    $notes[] = 'Ambiguous assignment: assignments #'.implode(', #', $pick['tied_ids'])." tie on specificity, override, priority and start date; #{$assignmentId} was used (highest id). Give them distinct priorities.";
                }
                if ($row['rotation_id']) {
                    $rotation = $this->rotations[$row['rotation_id']] ?? null;
                    if ($rotation) {
                        $anchor = $row['rotation_anchor'] ?: $row['effective_from'];
                        $days = (int) CarbonImmutable::parse($anchor)->diffInDays(CarbonImmutable::parse($date), false);
                        $n = (int) $rotation['cycle_length'];
                        $dayIndex = ((($days % $n) + $n) % $n) + 1;
                        $pattern = $this->patterns[$rotation['id']][$dayIndex] ?? null;
                        $source = 'rotation';
                        if ($pattern && $pattern['shift_id'] && isset($this->shifts[$pattern['shift_id']])) {
                            $shift = $this->shifts[$pattern['shift_id']];
                            $kind = 'working';
                            $notes[] = "Rotation {$rotation['code']} day {$dayIndex} of {$n} is shift {$shift['code']} (assignment #{$assignmentId}, {$why}).";
                        } else {
                            $kind = 'weekly_off';
                            $notes[] = "Rotation {$rotation['code']} day {$dayIndex} of {$n} is a day off (assignment #{$assignmentId}, {$why}).";
                        }
                    }
                } elseif ($row['shift_id'] && isset($this->shifts[$row['shift_id']])) {
                    $shift = $this->shifts[$row['shift_id']];
                    $source = 'assignment';
                    $notes[] = "Shift {$shift['code']} from assignment #{$assignmentId} ({$why}).";
                }
            } else {
                $notes[] = 'No shift assignment applies.';
            }
        }

        // (5) work week
        $week = $this->workWeekFor($ctx, $date);
        $workDay = null;
        if ($week) {
            $workDay = $week['days'][$weekday] ?? ['type' => 'off', 'minutes' => null];
            $notes[] = "Work week {$week['code']} v{$week['version']}: ".strtolower(CarbonImmutable::parse($date)->format('l')).' is '.$workDay['type'].'.';
        } else {
            $workDay = ['type' => in_array($weekday, $this->defaultWorkingDays, true) ? 'full' : 'off', 'minutes' => null];
            $notes[] = 'No work week assigned; using the workspace default ('.strtolower(CarbonImmutable::parse($date)->format('l')).' is '.$workDay['type'].').';
        }
        if (in_array($source, ['assignment', 'none'], true)) {
            $override = $shift['rules']['weekly_off_days'] ?? null;
            if ($shift && is_array($override)) {
                $isOff = in_array($weekday, array_map('intval', $override), true);
                $workDay = ['type' => $isOff ? 'off' : 'full', 'minutes' => null];
                $notes[] = "Shift {$shift['code']} defines its own weekly off days; the work week is not used for weekly off.";
            }
            if ($workDay['type'] === 'off') {
                $kind = 'weekly_off';
                $shift = null;
            } elseif ($shift) {
                $kind = 'working';
            } else {
                $kind = 'not_scheduled';
                $notes[] = 'No shift is assigned for a working day.';
            }
        }

        // (6) holiday
        $holiday = null;
        $pick = AssignmentResolver::pick($this->holidayAssignments, $ctx, $date);
        if ($pick) {
            $calendarId = (int) $pick['row']['calendar_id'];
            $h = $this->holidays[$calendarId][$date] ?? null;
            if ($h) {
                $holiday = ['name' => $h['name'], 'type' => $h['type'], 'portion' => $h['portion'], 'calendar_id' => $calendarId, 'calendar_code' => $this->calendars[$calendarId]['code'] ?? null];
                $label = "Holiday '{$h['name']}' ({$h['type']}, {$h['portion']}) from calendar ".($this->calendars[$calendarId]['code'] ?? '#'.$calendarId).'.';
                if ($pick['ambiguous']) {
                    $notes[] = 'Ambiguous holiday calendar assignments #'.implode(', #', $pick['tied_ids']).'.';
                }
                if ($h['portion'] === 'full') {
                    if ($source === 'roster' && $kind === 'working') {
                        $notes[] = $label.' The published roster explicitly schedules work, so the day stays a working day.';
                    } else {
                        $notes[] = $label.($kind === 'weekly_off' ? ' It also falls on a weekly off.' : '');
                        $kind = 'holiday';
                    }
                } else {
                    $notes[] = $label.($kind === 'working' ? ' Required hours are reduced.' : '');
                }
            }
        }

        return array_merge($base, ['kind' => $kind ?? 'not_scheduled', 'source' => $source, 'shift' => $shift, 'roster_entry' => $rosterRow, 'assignment_id' => $assignmentId, 'work_week' => $week,
            'work_day' => $workDay, 'holiday' => $holiday, 'notes' => $notes, 'day_index' => $dayIndex, 'ambiguous' => $ambiguous, 'timezone' => $shift['timezone'] ?? ($ctx['location_timezone'] ?? $base['timezone'])]);
    }

    /** The work-week version effective on the date for the winning work-week assignment. */
    private function workWeekFor(array $ctx, string $date): ?array
    {
        $pick = AssignmentResolver::pick($this->workWeekAssignments, $ctx, $date);
        if (! $pick) {
            return null;
        }
        $assigned = $this->workWeekById[$pick['row']['work_week_id']] ?? null;
        if (! $assigned) {
            return null;
        }
        $best = null;
        foreach ($this->workWeeks[$assigned['code']] ?? [] as $v) {
            if ($v['active'] && $v['effective_from'] <= $date && ($v['effective_to'] === null || $v['effective_to'] >= $date) && (! $best || $v['version'] > $best['version'])) {
                $best = $v;
            }
        }

        return $best;
    }

    private function targetName(string $type, string $id, array $ctx): ?string
    {
        $tables = ['team' => 'teams', 'department' => 'departments', 'business_unit' => 'business_units', 'location' => 'locations', 'employee_type' => 'employee_types',
            'legal_entity' => 'legal_entities', 'employee_group' => 'employee_groups'];
        if ($type === 'employee') {
            return $this->org->employee((int) $id)['employee_number'] ?? null;
        }
        if ($type === 'country') {
            return $id;
        }
        if (! isset($tables[$type])) {
            return null;
        }

        return $this->names[$type][$id] ??= DB::table($tables[$type])->where('tenant_id', app(TenantContext::class)->id)->where('id', $id)->value('name');
    }
}
