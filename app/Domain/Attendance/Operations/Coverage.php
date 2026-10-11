<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only "is the employee covered?" lookups over the polymorphic assignment tables (shift, policy, holiday calendar,
 * work week). Used by the command center, readiness and the data-quality rules to find gaps (no shift, no policy, ...).
 *
 * It mirrors the documented resolution order of ScheduleResolver (roster > assignment/rotation > work week/holiday)
 * for the question "is anything assigned?" only; it never computes times, and it never writes.
 */
final class Coverage
{
    /** Most specific first. */
    public const SPECIFICITY = ['employee', 'team', 'department', 'employee_group', 'business_unit', 'location', 'employee_type', 'legal_entity', 'country', 'tenant'];

    private const DIMENSION = ['team' => 'team_id', 'department' => 'department_id', 'business_unit' => 'business_unit_id', 'location' => 'location_id', 'employee_type' => 'employee_type_id', 'legal_entity' => 'legal_entity_id'];

    /**
     * Org dimensions of each employee AS OF the date (current effective-dated assignment, never "now").
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<string,mixed>>
     */
    public function dims(array $ids, string $date): array
    {
        $tenant = Ops::tenantId();
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
            foreach (DB::table('employees')->where('tenant_id', $tenant)->whereIn('id', $chunk)->get(['id', 'country']) as $e) {
                $out[(int) $e->id] = ['employee' => (int) $e->id, 'country' => $e->country, 'groups' => []] + array_fill_keys(array_keys(self::DIMENSION), null);
            }
            $assignments = DB::table('employee_assignments')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)->where('effective_from', '<=', $date)
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
            foreach ($assignments as $a) {
                foreach (self::DIMENSION as $type => $column) {
                    $out[(int) $a->employee_id][$type] = $a->{$column} === null ? null : (int) $a->{$column};
                }
            }
            $members = DB::table('employee_group_members')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)
                ->where(fn ($w) => $w->whereNull('effective_from')->orWhere('effective_from', '<=', $date))->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
            foreach ($members as $m) {
                $out[(int) $m->employee_id]['groups'][] = (int) $m->group_id;
            }
        }

        return $out;
    }

    /**
     * Best matching assignment row per employee (most specific target, then override, priority, latest start).
     *
     * @param  array<int,int>  $ids
     * @return array<int,object> employeeId => assignment row (employees without a match are absent)
     */
    public function best(string $table, array $ids, string $date, ?\Closure $filter = null, ?array $dims = null): array
    {
        if (! $ids) {
            return [];
        }
        $dims ??= $this->dims($ids, $date);
        $q = DB::table($table)->where('tenant_id', Ops::tenantId())->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
        if ($filter) {
            $filter($q);
        }
        $byType = $q->get()->groupBy('target_type');
        $out = [];
        foreach ($ids as $id) {
            $d = $dims[$id] ?? null;
            if (! $d) {
                continue;
            }
            foreach (self::SPECIFICITY as $type) {
                $candidates = ($byType[$type] ?? collect())->filter(fn ($row) => $this->matches($type, (string) $row->target_id, $d));
                if ($candidates->isNotEmpty()) {
                    $out[$id] = $candidates->sort(fn ($a, $b) => [(int) $b->is_override, (int) $b->priority, $b->effective_from, $b->id] <=> [(int) $a->is_override, (int) $a->priority, $a->effective_from, $a->id])->first();

                    break;
                }
            }
        }

        return $out;
    }

    private function matches(string $type, string $target, array $d): bool
    {
        return match ($type) {
            'employee' => (string) $d['employee'] === $target,
            'tenant' => true,
            'country' => $target === '*' || strtoupper($target) === strtoupper((string) $d['country']),
            'employee_group' => in_array((int) $target, $d['groups'], true),
            default => isset($d[$type]) && (string) $d[$type] === $target,
        };
    }

    /**
     * Expected-work state of each employee on a date.
     *   working     a shift applies (roster entry, assignment or rotation day)
     *   off         explicit roster "off", rotation off day, weekly off or full holiday
     *   unassigned  nothing assigns a shift (a roster gap)
     *
     * @param  array<int,int>  $ids
     * @return array<int,array{state:string,shift_id:?int,reason:?string}>
     */
    public function shiftStates(array $ids, string $date): array
    {
        $tenant = Ops::tenantId();
        $out = [];
        if (! $ids) {
            return $out;
        }
        $roster = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            foreach (DB::table('roster_entries')->where('tenant_id', $tenant)->where('work_date', $date)->where('status', 'published')->whereIn('employee_id', $chunk)->orderBy('id')->get() as $r) {
                $roster[(int) $r->employee_id] = $r;
            }
        }
        $dims = $this->dims($ids, $date);
        $rest = array_values(array_filter($ids, fn ($id) => ! isset($roster[$id]) || $roster[$id]->entry_type === 'cleared'));
        $assigned = $this->best('shift_assignments', $rest, $date, fn ($q) => $q->where(fn ($w) => $w->whereNotNull('shift_id')->orWhereNotNull('rotation_id')), $dims);
        $shifts = DB::table('shifts')->where('tenant_id', $tenant)->get(['id', 'active'])->keyBy('id');
        $patterns = [];
        $rotationIds = collect($assigned)->pluck('rotation_id')->filter()->unique()->all();
        if ($rotationIds) {
            foreach (DB::table('shift_rotations')->where('tenant_id', $tenant)->whereIn('id', $rotationIds)->get(['id', 'cycle_length']) as $rotation) {
                $patterns[$rotation->id] = ['cycle' => (int) $rotation->cycle_length, 'days' => DB::table('shift_rotation_patterns')->where('rotation_id', $rotation->id)->pluck('shift_id', 'day_index')->all()];
            }
        }
        $working = [];
        foreach ($ids as $id) {
            if (isset($roster[$id]) && $roster[$id]->entry_type !== 'cleared') {
                $r = $roster[$id];
                $out[$id] = $r->entry_type === 'shift' && $r->shift_id ? ['state' => 'working', 'shift_id' => (int) $r->shift_id, 'reason' => 'roster'] : ['state' => 'off', 'shift_id' => null, 'reason' => 'roster_off'];

                continue;
            }
            $a = $assigned[$id] ?? null;
            if (! $a) {
                $out[$id] = ['state' => 'unassigned', 'shift_id' => null, 'reason' => 'no_assignment'];

                continue;
            }
            $shiftId = $a->shift_id ? (int) $a->shift_id : null;
            if ($a->rotation_id) {
                $p = $patterns[$a->rotation_id] ?? null;
                if (! $p || $p['cycle'] < 1 || ! $a->rotation_anchor) {
                    $out[$id] = ['state' => 'unassigned', 'shift_id' => null, 'reason' => 'rotation_incomplete'];

                    continue;
                }
                $offset = CarbonImmutable::parse($a->rotation_anchor)->diffInDays(CarbonImmutable::parse($date), false);
                $index = (((int) $offset % $p['cycle']) + $p['cycle']) % $p['cycle'] + 1;
                if (! array_key_exists($index, $p['days'])) {
                    $out[$id] = ['state' => 'unassigned', 'shift_id' => null, 'reason' => 'rotation_incomplete'];

                    continue;
                }
                $shiftId = $p['days'][$index] ? (int) $p['days'][$index] : null;
                if ($shiftId === null) {
                    $out[$id] = ['state' => 'off', 'shift_id' => null, 'reason' => 'rotation_off'];

                    continue;
                }
            }
            if (! $shiftId || ! ($shifts[$shiftId]->active ?? false)) {
                $out[$id] = ['state' => 'unassigned', 'shift_id' => null, 'reason' => 'shift_inactive'];

                continue;
            }
            $out[$id] = ['state' => 'working', 'shift_id' => $shiftId, 'reason' => 'assignment'];
            $working[] = $id;
        }
        foreach ($this->offDays($working, $date, $dims) as $id => $reason) {
            $out[$id] = ['state' => 'off', 'shift_id' => null, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * Employees (of the given ids) whose work week marks the date off, or whose calendar has a full holiday on it.
     *
     * @return array<int,string> employeeId => weekly_off | holiday
     */
    public function offDays(array $ids, string $date, ?array $dims = null): array
    {
        if (! $ids) {
            return [];
        }
        $tenant = Ops::tenantId();
        $dims ??= $this->dims($ids, $date);
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
        $out = [];
        $weeks = $this->best('work_week_assignments', $ids, $date, null, $dims);
        $offByWeek = DB::table('work_week_days')->where('tenant_id', $tenant)->where('weekday', $weekday)->where('type', 'off')->pluck('work_week_id')->flip();
        $defaultWorking = TenantClock::setting('work_week', [1, 2, 3, 4, 5]);
        foreach ($ids as $id) {
            $week = $weeks[$id] ?? null;
            if ($week ? isset($offByWeek[$week->work_week_id]) : ! in_array($weekday, $defaultWorking, true)) {
                $out[$id] = 'weekly_off';
            }
        }
        $calendars = $this->best('employee_holiday_calendar_assignments', $ids, $date, null, $dims);
        if ($calendars) {
            $holidayCalendars = DB::table('holidays')->where('tenant_id', $tenant)->where('date', $date)->where('portion', 'full')->pluck('calendar_id')->flip();
            foreach ($calendars as $id => $row) {
                if (isset($holidayCalendars[$row->calendar_id])) {
                    $out[$id] = 'holiday';
                }
            }
        }

        return $out;
    }

    /**
     * Employees with an attendance policy in force on the date: a policy assignment whose policy is active and has a
     * published version effective on the date, or an employee_policy_references "attendance_policy" entry.
     *
     * @return array<int,int> employeeId => policy id
     */
    public function policies(array $ids, string $date, ?array $dims = null): array
    {
        if (! $ids) {
            return [];
        }
        $tenant = Ops::tenantId();
        $usable = DB::table('attendance_policies as ap')->join('attendance_policy_versions as v', 'v.policy_id', '=', 'ap.id')->where('ap.tenant_id', $tenant)->where('ap.active', true)
            ->where('v.status', 'published')->where('v.effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $date))->pluck('ap.id')->flip();
        $assigned = $this->best('attendance_policy_assignments', $ids, $date, fn ($q) => $q->whereIn('policy_id', $usable->keys()->all() ?: [0]), $dims);
        $out = [];
        foreach ($assigned as $id => $row) {
            $out[$id] = (int) $row->policy_id;
        }
        $refs = DB::table('employee_policy_references')->where('tenant_id', $tenant)->where('kind', 'attendance_policy')->whereIn('employee_id', $ids)->where('effective_from', '<=', $date)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
        foreach ($refs as $ref) {
            if (isset($usable[$ref->reference_id])) {
                $out[(int) $ref->employee_id] = (int) $ref->reference_id;
            }
        }

        return $out;
    }

    /** Employees that have a holiday calendar assigned on the date. @return array<int,int> employeeId => calendar id */
    public function calendars(array $ids, string $date, ?array $dims = null): array
    {
        $out = [];
        foreach ($this->best('employee_holiday_calendar_assignments', $ids, $date, null, $dims) as $id => $row) {
            $out[$id] = (int) $row->calendar_id;
        }

        return $out;
    }
}
