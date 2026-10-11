<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarAssignment;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftRotation;
use App\Domain\Attendance\Models\WorkWeek;
use App\Domain\Attendance\Models\WorkWeekAssignment;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create / end / list effective-dated assignments of shifts (or rotations), work weeks and holiday calendars to polymorphic
 * targets, with WRITE-TIME ambiguity rejection:
 *   - same kind, same target, overlapping dates, equal override flag and priority  => rejected
 *   - two different employee_group targets with overlapping dates and equal override flag and priority => rejected (a person can
 *     be in both groups, so resolution would be a coin toss); give them distinct priorities.
 * Started assignments are history: only their end date can change; delete is for future-dated rows.
 */
final class ScheduleAssignmentService
{
    public const KINDS = [
        'shift' => ['model' => ShiftAssignment::class, 'table' => 'shift_assignments', 'label' => 'shift'],
        'work_week' => ['model' => WorkWeekAssignment::class, 'table' => 'work_week_assignments', 'label' => 'work week'],
        'holiday' => ['model' => HolidayCalendarAssignment::class, 'table' => 'employee_holiday_calendar_assignments', 'label' => 'holiday calendar'],
    ];

    /** @return array<string,mixed> presented assignment */
    public function create(string $kind, array $in, User $actor, bool $supersede = false): array
    {
        $config = self::KINDS[$kind];
        $row = $this->normalize($kind, $in, $actor);
        $this->assertNotAmbiguous($kind, $row, null, $supersede);

        return DB::transaction(function () use ($kind, $config, $row, $actor, $supersede) {
            $affected = $this->affectedEmployees($row);
            SchedulingEffects::assertRangeUnlocked($affected, $row['effective_from'], $row['effective_to'] ?? '9999-12-31');
            if ($supersede) {
                $this->endCovering($kind, $row, $actor);
            }
            /** @var Model $model */
            $model = $config['model']::create($row + ['created_by' => $actor->id]);
            Audit::record('created', 'attendance', $config['label'].'_assignment:'.($model->uuid ?? $model->id), ['target' => $row['target_type'].':'.$row['target_id'], 'from' => $row['effective_from'], 'to' => $row['effective_to']]);
            SchedulingEffects::recalculate($affected, $row['effective_from'], $row['effective_to'] ?? SchedulingEffects::today(), $actor->id, 'assignment:'.$kind.':'.$model->id);

            return $this->present($kind, $model);
        });
    }

    /** Ends a started assignment (effective_to) or edits a future one entirely. */
    public function update(string $kind, Model $model, array $in, User $actor): array
    {
        $config = self::KINDS[$kind];
        $started = $model->effective_from <= SchedulingEffects::today();
        if ($started) {
            $allowed = array_intersect_key($in, array_flip(['effective_to', 'note']));
            if (array_diff(array_keys($in), ['effective_to', 'note', 'priority', 'is_override'])) {
                Fail::field('effective_from', 'This assignment has already started. Create a new assignment from the change date (it will end this one) so history stays intact.');
            }
            foreach (['priority', 'is_override'] as $f) {
                if (array_key_exists($f, $in) && (string) $in[$f] !== (string) $model->{$f}) {
                    Fail::field($f, 'Priority and override cannot change after an assignment has started because that would rewrite past schedules. Create a new assignment instead.');
                }
            }
            $payload = array_merge($this->current($kind, $model), $allowed);
        } else {
            $payload = array_merge($this->current($kind, $model), $in);
        }
        $row = $this->normalize($kind, $payload, $actor, $model);
        $this->assertNotAmbiguous($kind, $row, $model->id, false);

        return DB::transaction(function () use ($kind, $config, $model, $row, $actor) {
            $oldTo = $model->effective_to;
            $before = ['from' => $model->effective_from, 'to' => $oldTo];
            $a = $oldTo ?? '9999-12-31';
            $b = $row['effective_to'] ?? '9999-12-31';
            $started = $model->effective_from <= SchedulingEffects::today();
            // A started assignment only changes in its tail; a future one changes over its whole range.
            $tailFrom = $started && $a !== $b ? CarbonImmutable::parse(min($a, $b))->addDay()->toDateString() : min($row['effective_from'], $model->effective_from);
            $tailTo = $started && $a !== $b ? max($a, $b) : max($b, $a);
            $affected = TargetMembers::employeeIds($row['target_type'], $row['target_id'], $tailFrom, $tailTo);
            SchedulingEffects::assertRangeUnlocked($affected, $tailFrom, $tailTo);
            $model->update($row);
            Audit::record('updated', 'attendance', $config['label'].'_assignment:'.($model->uuid ?? $model->id), ['before' => $before, 'after' => ['from' => $row['effective_from'], 'to' => $row['effective_to']]]);
            SchedulingEffects::recalculate($affected, $tailFrom, $tailTo, $actor->id, 'assignment:'.$kind.':'.$model->id);

            return $this->present($kind, $model);
        });
    }

    public function delete(string $kind, Model $model, User $actor): void
    {
        if ($model->effective_from <= SchedulingEffects::today()) {
            Fail::business('assignment_started', 'This assignment has already started, so deleting it would erase history. End it instead by setting an end date.', 409);
        }
        $config = self::KINDS[$kind];
        DB::transaction(function () use ($config, $model) {
            Audit::record('deleted', 'attendance', $config['label'].'_assignment:'.($model->uuid ?? $model->id), ['target' => $model->target_type.':'.$model->target_id, 'from' => $model->effective_from]);
            $model->delete();
        });
    }

    /** @return array<string,mixed> */
    public function present(string $kind, Model $model): array
    {
        $out = ['id' => $model->uuid ?? $model->id, 'kind' => $kind, 'target_type' => $model->target_type, 'target_id' => $model->target_id, 'target_name' => $this->targetName($model->target_type, $model->target_id),
            'effective_from' => $model->effective_from, 'effective_to' => $model->effective_to, 'priority' => (int) $model->priority, 'is_override' => (bool) $model->is_override, 'note' => $model->note,
            'specificity' => Specificity::rank($model->target_type)];
        if ($kind === 'shift') {
            $out['shift'] = $model->shift_id ? DB::table('shifts')->where('id', $model->shift_id)->first(['name', 'code', 'uuid']) : null;
            $out['rotation'] = $model->rotation_id ? DB::table('shift_rotations')->where('id', $model->rotation_id)->first(['name', 'code', 'uuid']) : null;
            $out['rotation_anchor'] = $model->rotation_anchor;
        } elseif ($kind === 'work_week') {
            $out['work_week'] = DB::table('work_weeks')->where('id', $model->work_week_id)->first(['name', 'code', 'uuid', 'version']);
        } else {
            $out['calendar'] = DB::table('holiday_calendars')->where('id', $model->calendar_id)->first(['name', 'code', 'uuid']);
        }

        return $out;
    }

    public function targetName(string $type, string $id): ?string
    {
        $tenant = app(TenantContext::class)->id;
        if ($type === 'tenant') {
            return 'Everyone';
        }
        if ($type === 'country') {
            return $id;
        }
        if ($type === 'employee') {
            $e = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $tenant)->where('employees.id', $id)->first(['people.display_name', 'employees.employee_number']);

            return $e ? trim(($e->display_name ?? '').' ('.$e->employee_number.')') : null;
        }
        $table = $type === 'employee_group' ? 'employee_groups' : (TargetMembers::ORGANIZATION[$type][0] ?? null);

        return $table ? DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->value('name') : null;
    }

    // ------------------------------------------------------------------------------------------------------------------------------

    /** Existing row as input (for partial updates). */
    private function current(string $kind, Model $m): array
    {
        $base = ['target_type' => $m->target_type, 'target_id' => $m->target_id, 'effective_from' => $m->effective_from, 'effective_to' => $m->effective_to, 'priority' => $m->priority, 'is_override' => $m->is_override, 'note' => $m->note];
        if ($kind === 'shift') {
            $base['shift_uuid'] = $m->shift_id ? Shift::query()->whereKey($m->shift_id)->value('uuid') : null;
            $base['rotation_uuid'] = $m->rotation_id ? ShiftRotation::query()->whereKey($m->rotation_id)->value('uuid') : null;
            $base['rotation_anchor'] = $m->rotation_anchor;
        } elseif ($kind === 'work_week') {
            $base['work_week_uuid'] = WorkWeek::query()->whereKey($m->work_week_id)->value('uuid');
        } else {
            $base['calendar_uuid'] = HolidayCalendar::query()->whereKey($m->calendar_id)->value('uuid');
        }

        return $base;
    }

    /** @return array<string,mixed> column values */
    private function normalize(string $kind, array $in, User $actor, ?Model $existing = null): array
    {
        $errors = [];
        $type = $in['target_type'] ?? null;
        if (! in_array($type, Specificity::types(), true)) {
            $errors['target_type'] = 'Choose who this applies to: an employee, team, department, group, business unit, location, employee type, legal entity, country or everyone.';
        }
        $targetId = isset($in['target_id']) ? (string) $in['target_id'] : ($type === 'tenant' ? '*' : '');
        if ($type === 'tenant') {
            $targetId = '*';
        } elseif ($type === 'country') {
            $targetId = strtoupper($targetId);
        } elseif ($type === 'employee' && $targetId !== '' && ! ctype_digit($targetId)) {
            $targetId = (string) (Employee::query()->where('uuid', $targetId)->value('id') ?? '');
        }
        if ($type && ! isset($errors['target_type']) && ! TargetMembers::exists($type, $targetId)) {
            $errors['target_id'] = $type === 'country' ? 'Enter a two-letter country code such as IN.' : 'We could not find that '.Specificity::label($type).' in your workspace.';
        }
        $from = $in['effective_from'] ?? null;
        $to = $in['effective_to'] ?? null;
        if (! is_string($from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))) {
            $errors['effective_from'] = 'Enter the date this takes effect (YYYY-MM-DD).';
        }
        if ($to !== null && (! is_string($to) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || (is_string($from) && $to < $from))) {
            $errors['effective_to'] = 'The end date must be a valid date on or after the start date, or empty for open-ended.';
        }
        $priority = $in['priority'] ?? 0;
        if (! is_numeric($priority) || $priority < -1000 || $priority > 1000) {
            $errors['priority'] = 'Priority must be a whole number between -1000 and 1000.';
        }
        $row = ['target_type' => $type, 'target_id' => $targetId, 'effective_from' => $from, 'effective_to' => $to, 'priority' => (int) $priority, 'is_override' => (bool) ($in['is_override'] ?? false), 'note' => $in['note'] ?? null];

        if ($kind === 'shift') {
            $shift = ! empty($in['shift_uuid']) ? Shift::query()->where('uuid', $in['shift_uuid'])->first() : null;
            $rotation = ! empty($in['rotation_uuid']) ? ShiftRotation::query()->where('uuid', $in['rotation_uuid'])->first() : null;
            if (($shift === null) === ($rotation === null)) {
                $errors['shift_uuid'] = 'Choose either one shift or one rotation (not both).';
            } elseif ($shift && ! $shift->active && (! $existing || $existing->shift_id !== $shift->id)) {
                $errors['shift_uuid'] = "Shift {$shift->code} is inactive. Activate it or choose another shift.";
            } elseif ($rotation && ! $rotation->active && (! $existing || $existing->rotation_id !== $rotation->id)) {
                $errors['rotation_uuid'] = "Rotation {$rotation->code} is inactive.";
            }
            $anchor = $in['rotation_anchor'] ?? null;
            if ($rotation && (! is_string($anchor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor))) {
                $errors['rotation_anchor'] = 'A rotation needs an anchor date: the date on which day 1 of the cycle falls.';
            }
            $row += ['shift_id' => $shift?->id, 'rotation_id' => $rotation?->id, 'rotation_anchor' => $rotation ? $anchor : null];
        } elseif ($kind === 'work_week') {
            $week = ! empty($in['work_week_uuid']) ? WorkWeek::query()->where('uuid', $in['work_week_uuid'])->first() : null;
            if (! $week) {
                $errors['work_week_uuid'] = 'Choose a work week.';
            }
            $row += ['work_week_id' => $week?->id];
        } else {
            $calendar = ! empty($in['calendar_uuid']) ? HolidayCalendar::query()->where('uuid', $in['calendar_uuid'])->first() : null;
            if (! $calendar) {
                $errors['calendar_uuid'] = 'Choose a holiday calendar.';
            }
            $row += ['calendar_id' => $calendar?->id];
        }
        // Authorization: an employee target must be inside the editor's data scope; wider targets need workspace-wide reach.
        if (! $errors && $type) {
            $scope = app(DataScope::class);
            $permission = 'attendance.roster.manage';
            if ($type === 'employee') {
                abort_unless($scope->canAccess($actor, (int) $targetId, $permission), 404);
            } else {
                abort_unless($scope->hasTenantWide($actor, $permission), 403, 'Assigning schedules to a whole team, department, location or group needs workspace-wide scheduling access.');
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }

        return $row;
    }

    /** Rejects ambiguous configurations; with $supersede the covering same-target rows that start earlier are tolerated (they get ended). */
    private function assertNotAmbiguous(string $kind, array $row, ?int $ignoreId, bool $supersede): void
    {
        $table = self::KINDS[$kind]['table'];
        $label = self::KINDS[$kind]['label'];
        $query = DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('effective_from', '<=', $row['effective_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $row['effective_from']));

        // 1. Same target, equal override/priority, overlapping dates.
        $same = (clone $query)->where('target_type', $row['target_type'])->where('target_id', $row['target_id'])->get();
        foreach ($same as $other) {
            $equal = (int) $other->priority === (int) $row['priority'] && (bool) $other->is_override === (bool) $row['is_override'];
            if ($supersede && $other->effective_from < $row['effective_from']) {
                continue; // it will be ended the day before
            }
            if ($equal) {
                Fail::business('ambiguous_assignment', "This {$label} assignment overlaps another {$label} assignment for the same ".Specificity::label($row['target_type']).' ('
                    .$other->effective_from.' to '.($other->effective_to ?? 'open-ended').') with the same priority, so it would be unclear which one applies. '
                    .'Change the dates, give them different priorities, or end the existing one first.', 422, ['conflicts_with' => $other->id]);
            }
        }
        // 2. Groups: one person can be in several groups, so equal priority between groups is ambiguous.
        if ($row['target_type'] === 'employee_group') {
            $others = (clone $query)->where('target_type', 'employee_group')->where('target_id', '!=', $row['target_id'])->where('priority', $row['priority'])->where('is_override', $row['is_override'])->first();
            if ($others) {
                Fail::business('ambiguous_group_assignment', "Another group already has a {$label} assignment in these dates with the same priority. People who belong to both groups would get an unpredictable result. Give each group a distinct priority.", 422, ['conflicts_with' => $others->id]);
            }
        }
    }

    /** Ends same-target rows that started before the new row (closing the day before). */
    private function endCovering(string $kind, array $row, User $actor): void
    {
        $table = self::KINDS[$kind]['table'];
        $end = CarbonImmutable::parse($row['effective_from'])->subDay()->toDateString();
        DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->where('target_type', $row['target_type'])->where('target_id', $row['target_id'])
            ->where('effective_from', '<', $row['effective_from'])->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $row['effective_from']))
            ->update(['effective_to' => $end, 'updated_at' => now()]);
    }

    /** Employees reached by the row; only needed when the row touches dates up to today (locks, recalculation). @return array<int,int> */
    private function affectedEmployees(array $row): array
    {
        if ($row['effective_from'] > SchedulingEffects::today()) {
            return [];
        }

        return TargetMembers::employeeIds($row['target_type'], $row['target_id'], $row['effective_from'], $row['effective_to'] ?? '9999-12-31');
    }
}
