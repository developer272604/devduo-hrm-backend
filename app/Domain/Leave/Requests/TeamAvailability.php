<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Scheduling\HolidayService;
use App\Domain\Leave\Restrictions\TeamScope;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Team Availability Service: who is away, who is available. Always limited to the viewer's data scope (leave.team.view);
 * sensitive leave types are shown as plain "Away" unless the viewer may see medical detail.
 */
final class TeamAvailability
{
    public function __construct(private DataScope $scope, private RequestPresenter $presenter, private TeamScope $teams, private HolidayService $holidays) {}

    /** Away entries (one per request, collapsed to its date span) for employees in scope. */
    public function calendar(User $viewer, string $from, string $to, bool $includePending = true, bool $includeSelf = true, ?array $employeeUuids = null): array
    {
        $rows = $this->segments($viewer, $from, $to, $includePending, $includeSelf, $employeeUuids);
        $entries = [];
        foreach ($rows->groupBy('request_id') as $segments) {
            $first = $segments->first();
            $employee = Employee::query()->with('person')->find($first->employee_id);
            $reveal = $this->presenter->canSeeSensitive($viewer, $employee);
            $type = $this->presenter->type((int) $first->leave_type_id, $reveal);
            $dates = $segments->pluck('leave_date')->unique()->sort()->values();
            $entries[] = ['request' => $first->uuid, 'employee' => $this->presenter->employee($employee), 'type' => ['name' => $type['name'], 'color' => $type['color'] ?? null], 'status' => $first->status === 'pending' ? 'pending' : 'approved',
                'start_date' => $dates->first(), 'end_date' => $dates->last(), 'dates' => $dates->all(), 'portion' => $segments->count() === 1 ? $first->portion : 'full_day', 'self' => $this->scope->isSelf($viewer, $employee)];
        }
        usort($entries, fn ($a, $b) => [$a['start_date'], $a['employee']['name']] <=> [$b['start_date'], $b['employee']['name']]);

        return $entries;
    }

    /** Headcount picture for one date. @return array<string,mixed> */
    public function availability(User $viewer, string $date, bool $includePending = true): array
    {
        $ids = $this->scoped($viewer, $date)->pluck('employees.id')->map(fn ($i) => (int) $i)->all();
        $away = $this->teams->away($ids, $date, null, $includePending);
        $people = Employee::query()->with('person')->whereIn('id', array_keys($away))->get()->keyBy('id');
        $list = [];
        foreach ($away as $id => $fraction) {
            $list[] = ['employee' => $this->presenter->employee($people[$id]), 'fraction' => $fraction];
        }
        $total = count($ids);
        $sum = array_sum($away);

        return ['date' => $date, 'headcount' => $total, 'away' => round($sum, 2), 'available' => round($total - $sum, 2), 'available_percent' => $total ? round(($total - $sum) * 100 / $total, 1) : null,
            'away_list' => $list, 'holidays' => $this->ownHolidays($viewer, $date, $date)];
    }

    /** The viewer's own holidays in the range (their effective calendar). */
    public function ownHolidays(User $viewer, string $from, string $to): array
    {
        $own = $this->scope->ownEmployeeIds($viewer);

        return $own ? $this->holidays->forEmployee($own[0], $from, $to) : [];
    }

    private function scoped(User $viewer, string $date)
    {
        $q = DB::table('employees')->where('employees.tenant_id', app(TenantContext::class)->id)->whereNull('employees.deleted_at')->where('employees.joined_on', '<=', $date)
            ->where(fn ($qq) => $qq->whereNull('employees.separated_on')->orWhere('employees.separated_on', '>=', $date))->whereNotIn('employees.status', ['archived', 'preboarding']);

        return $this->scope->constrain($q, $viewer, 'leave.team.view');
    }

    private function segments(User $viewer, string $from, string $to, bool $includePending, bool $includeSelf, ?array $uuids)
    {
        $q = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->join('employees', 'employees.id', '=', 's.employee_id')->join('leave_types as t', 't.id', '=', 's.leave_type_id')
            ->where('s.tenant_id', app(TenantContext::class)->id)->whereBetween('s.leave_date', [$from, $to])->where('s.status', 'active')->where('s.charged', true)
            ->whereIn('r.status', $includePending ? ['pending', 'approved', 'partially_cancelled'] : ['approved', 'partially_cancelled'])
            ->when($uuids, fn ($qq) => $qq->whereIn('employees.uuid', $uuids))
            ->when(! $includeSelf, fn ($qq) => $qq->whereNotIn('s.employee_id', $this->scope->ownEmployeeIds($viewer) ?: [0]));
        $this->scope->constrain($q, $viewer, 'leave.team.view', 's.employee_id');

        return $q->orderBy('s.leave_date')->limit(5000)->get(['s.request_id', 's.employee_id', 's.leave_type_id', 's.leave_date', 's.portion', 'r.uuid', 'r.status']);
    }
}
