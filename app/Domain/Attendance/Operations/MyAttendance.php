<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Support\ExceptionType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** "My attendance" read model for the signed-in employee: today, the month so far and what is waiting. Facts only. */
final class MyAttendance
{
    public function __construct(private Coverage $coverage) {}

    public function summary(User $user): array
    {
        $emp = Ops::ownEmployee($user);
        if (! $emp) {
            return ['employee' => null, 'message' => 'No employee record is linked to your login yet, so there is no attendance to show.'];
        }
        $tenant = Ops::tenantId();
        $zone = $emp->timezone ?: \App\Domain\Tenancy\TenantClock::timezone();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($zone)->toDateString();

        $day = DB::table('attendance_days')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->where('attendance_date', $today)->first();
        $events = DB::table('attendance_events')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->where('local_date', $today)->whereNull('voided_at')->orderBy('punched_at')->get(['punched_at', 'direction', 'source']);
        $last = $events->last();
        $shiftId = $day?->shift_id;
        $state = null;
        if (! $day) {
            $state = $this->coverage->shiftStates([(int) $emp->id], $today)[(int) $emp->id] ?? null;
            $shiftId = $state['shift_id'] ?? null;
        }
        $shift = $shiftId ? DB::table('shifts')->where('tenant_id', $tenant)->where('id', $shiftId)->first(['name', 'code', 'start_time', 'end_time', 'timezone', 'full_day_minutes', 'duration_minutes']) : null;
        $status = $day->status ?? 'pending';
        $expected = $day ? (int) $day->required_minutes : (int) ($shift->full_day_minutes ?? $shift->duration_minutes ?? 0);

        $exceptions = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->whereIn('status', ['open', 'acknowledged'])->orderByDesc('attendance_date')->limit(5)->get(['uuid', 'type', 'severity', 'attendance_date']);
        $openExceptions = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->whereIn('status', ['open', 'acknowledged'])->count();

        return [
            'employee' => ['uuid' => $emp->uuid, 'employee_number' => $emp->employee_number, 'name' => $emp->display_name, 'timezone' => $zone],
            'date' => $today,
            'today' => [
                'status' => $status, 'status_label' => Ops::statusLabel($status), 'processed' => (bool) $day,
                'schedule_state' => $day ? null : ($state['state'] ?? null),
                'shift' => $shift ? ['name' => $shift->name, 'code' => $shift->code, 'start_time' => substr($shift->start_time, 0, 5), 'end_time' => substr($shift->end_time, 0, 5), 'timezone' => $shift->timezone] : null,
                'scheduled_start' => Ops::iso($day->scheduled_start ?? null), 'scheduled_end' => Ops::iso($day->scheduled_end ?? null),
                'first_in' => Ops::iso($day->first_in ?? $events->firstWhere('direction', 'IN')?->punched_at), 'last_out' => Ops::iso($day->last_out ?? null),
                'last_punch' => $last ? ['at' => Ops::iso($last->punched_at), 'direction' => $last->direction, 'source' => $last->source] : null,
                'checked_in' => $last && $last->direction === 'IN',
                'worked_minutes_so_far' => $this->workedMinutes($events, $now),
                'expected_minutes' => $expected, 'late_minutes' => (int) ($day->late_minutes ?? 0),
                'confidence' => Confidence::describe($day->confidence ?? Confidence::PENDING),
                'finalization' => $day->finalization ?? 'open',
            ],
            'open_exceptions' => ['count' => $openExceptions, 'recent' => $exceptions->map(fn ($x) => ['uuid' => $x->uuid, 'type' => $x->type, 'title' => ExceptionType::title($x->type), 'severity' => $x->severity, 'date' => $x->attendance_date])->all()],
            'pending_requests' => [
                'regularizations' => DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->where('status', 'pending')->count(),
                'overtime' => DB::table('overtime_requests')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->where('status', 'pending')->count(),
                'on_duty' => DB::table('on_duty_requests')->where('tenant_id', $tenant)->where('employee_id', $emp->id)->where('status', 'pending')->count(),
                'shift_swaps' => DB::table('shift_swap_requests')->where('tenant_id', $tenant)->where('requester_id', $emp->id)->whereIn('status', ['pending', 'counterpart_accepted'])->count(),
            ],
            'month' => $this->month((int) $emp->id, $today),
        ];
    }

    /** Month-to-date counts for one employee. */
    public function month(int $employeeId, string $today): array
    {
        $from = CarbonImmutable::parse($today)->startOfMonth()->toDateString();
        $days = DB::table('attendance_days')->where('tenant_id', Ops::tenantId())->where('employee_id', $employeeId)->whereBetween('attendance_date', [$from, $today])
            ->get(['status', 'late_minutes', 'early_exit_minutes', 'missing_punch', 'net_minutes', 'approved_overtime_minutes', 'finalization']);
        $counts = [];
        foreach ($days->countBy('status') as $status => $n) {
            $counts[$status] = ['count' => $n, 'label' => Ops::statusLabel($status)];
        }

        return [
            'from' => $from, 'to' => $today, 'days_recorded' => $days->count(), 'by_status' => $counts,
            'late_arrivals' => $days->where('late_minutes', '>', 0)->count(), 'early_departures' => $days->where('early_exit_minutes', '>', 0)->count(),
            'missing_punches' => $days->filter(fn ($d) => (bool) $d->missing_punch)->count(),
            'worked_minutes' => (int) $days->sum('net_minutes'), 'approved_overtime_minutes' => (int) $days->sum('approved_overtime_minutes'),
            'open_days' => $days->where('finalization', 'open')->count(),
        ];
    }

    /** Net minutes between IN/OUT pairs; an open IN counts until now. UNKNOWN directions alternate starting with IN. */
    public function workedMinutes($events, CarbonImmutable $now): int
    {
        $total = 0;
        $open = null;
        $expectIn = true;
        foreach ($events as $e) {
            $direction = $e->direction === 'UNKNOWN' ? ($expectIn ? 'IN' : 'OUT') : $e->direction;
            $at = CarbonImmutable::parse($e->punched_at, 'UTC');
            if ($direction === 'IN') {
                $open ??= $at;
                $expectIn = false;
            } elseif ($open) {
                $total += max(0, (int) $open->diffInMinutes($at));
                $open = null;
                $expectIn = true;
            }
        }
        if ($open) {
            $total += max(0, (int) $open->diffInMinutes($now));
        }

        return $total;
    }
}
