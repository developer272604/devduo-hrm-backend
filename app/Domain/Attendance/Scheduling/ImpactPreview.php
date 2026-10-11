<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\WorkWeek;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec 103: "this change will recalculate attendance for N employees across M dates" - computed WITHOUT writing anything.
 * Types: shift_assignment | roster_change | holiday | work_week. Only dates up to today have attendance to recalculate.
 */
final class ImpactPreview
{
    public const CONFIRM_THRESHOLD = 25;

    public function preview(string $type, array $in, User $actor): array
    {
        [$ids, $from, $to] = match ($type) {
            'shift_assignment' => $this->assignment($in),
            'roster_change' => $this->roster($in, $actor),
            'holiday' => $this->holiday($in),
            'work_week' => $this->workWeek($in),
            default => Fail::field('type', 'Type must be shift_assignment, roster_change, holiday or work_week.'),
        };
        $ids = array_values(array_unique($ids));
        $today = SchedulingEffects::today();
        $pastTo = min($to, $today);
        $dates = $from > $pastTo ? 0 : (int) \Carbon\CarbonImmutable::parse($from)->diffInDays($pastTo) + 1;
        $future = $to > $today ? (int) \Carbon\CarbonImmutable::parse(max($from, \Carbon\CarbonImmutable::parse($today)->addDay()->toDateString()))->diffInDays($to) + 1 : 0;
        $locked = 0;
        if ($dates > 0 && count($ids) <= 200) {
            foreach ($ids as $id) {
                if (SchedulingEffects::isLocked($id, $pastTo) || SchedulingEffects::isLocked($id, $from)) {
                    $locked++;
                }
            }
        }

        return ['type' => $type, 'affected_employees' => count($ids), 'from' => $from, 'to' => $to === '9999-12-31' ? null : $to, 'dates_to_recalculate' => $dates, 'future_dates' => $to === '9999-12-31' ? null : $future,
            'locked_employees' => $locked, 'requires_confirmation' => count($ids) * max(1, $dates) >= self::CONFIRM_THRESHOLD || $locked > 0,
            'message' => $dates > 0 ? 'This change will recalculate attendance for '.count($ids)." employee(s) across {$dates} date(s)." : 'This change only affects future dates; no existing attendance will be recalculated.'
                .($locked ? " {$locked} employee(s) have locked attendance in this period and will block the change." : '')];
    }

    private function assignment(array $in): array
    {
        $type = $in['target_type'] ?? null;
        if (! in_array($type, Specificity::types(), true)) {
            Fail::field('target_type', 'Choose who the assignment applies to.');
        }
        $from = $in['effective_from'] ?? Fail::field('effective_from', 'Enter the start date.');

        return [TargetMembers::employeeIds($type, $type === 'tenant' ? '*' : (string) ($in['target_id'] ?? ''), $from, $in['effective_to'] ?? '9999-12-31'), $from, $in['effective_to'] ?? '9999-12-31'];
    }

    private function roster(array $in, User $actor): array
    {
        $uuids = (array) ($in['employee_uuids'] ?? []);
        $q = Employee::query()->whereIn('uuid', $uuids);
        app(DataScope::class)->constrain($q, $actor, 'attendance.roster.manage');
        $ids = $q->pluck('id')->all();
        abort_unless(count($ids) === count(array_unique($uuids)), 404);
        $from = $in['from'] ?? Fail::field('from', 'Enter the first date.');

        return [$ids, $from, $in['to'] ?? $from];
    }

    private function holiday(array $in): array
    {
        $calendar = HolidayCalendar::query()->where('uuid', $in['calendar_uuid'] ?? '')->firstOrFail();
        $date = $in['date'] ?? Fail::field('date', 'Enter the holiday date.');
        $ids = [];
        foreach (DB::table('employee_holiday_calendar_assignments')->where('tenant_id', app(TenantContext::class)->id)->where('calendar_id', $calendar->id)->get(['target_type', 'target_id']) as $a) {
            $ids = array_merge($ids, TargetMembers::employeeIds($a->target_type, $a->target_id, $date, $date));
        }

        return [$ids, $date, $in['to'] ?? $date];
    }

    private function workWeek(array $in): array
    {
        $week = WorkWeek::query()->where('uuid', $in['work_week_uuid'] ?? '')->firstOrFail();
        $from = $in['effective_from'] ?? Fail::field('effective_from', 'Enter the date the change takes effect.');
        $ids = [];
        $weekIds = WorkWeek::query()->where('code', $week->code)->pluck('id');
        foreach (DB::table('work_week_assignments')->where('tenant_id', app(TenantContext::class)->id)->whereIn('work_week_id', $weekIds)->get(['target_type', 'target_id']) as $a) {
            $ids = array_merge($ids, TargetMembers::employeeIds($a->target_type, $a->target_id, $from, '9999-12-31'));
        }

        return [$ids, $from, '9999-12-31'];
    }
}
