<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Support\ExceptionType;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance context for Employee 360: today, current shift, recent exceptions, regularizations, upcoming shifts.
 * Facts only, each with its source; no trends, no judgments. Shown to the employee, and to viewers whose data scope
 * (attendance.team.view / attendance.manage / attendance.reports.view) reaches the employee. Never includes coordinates.
 */
final class AttendanceEmployee360Provider implements Employee360Provider
{
    private const VIEW = ['attendance.team.view', 'attendance.manage', 'attendance.reports.view'];

    public function __construct(private Coverage $coverage, private DataScope $scope) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        $allowed = ($relation['self'] && $viewer->allows('attendance.view'));
        foreach (self::VIEW as $permission) {
            $allowed = $allowed || ($viewer->allows($permission) && $this->scope->canAccess($viewer, $employee, $permission));
        }
        if (! $allowed) {
            return [];
        }
        $tenant = Ops::tenantId();
        $today = Ops::today();
        $out = [];
        $item = fn (string $key, string $label, mixed $value = null, ?string $detail = null, ?string $date = null) => array_filter(['key' => $key, 'label' => $label, 'value' => $value, 'detail' => $detail, 'date' => $date, 'source' => 'attendance'], fn ($v) => $v !== null);

        $day = DB::table('attendance_days')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('attendance_date', $today)->first();
        $state = $day ? null : ($this->coverage->shiftStates([(int) $employee->id], $today)[(int) $employee->id] ?? null);
        $shiftId = $day->shift_id ?? ($state['shift_id'] ?? null);
        $shift = $shiftId ? DB::table('shifts')->where('tenant_id', $tenant)->where('id', $shiftId)->first(['name', 'start_time', 'end_time', 'timezone']) : null;
        $items = [];
        if ($day) {
            $items[] = $item('status', 'Status today', Ops::statusLabel($day->status), null, $today);
            if ($day->first_in) {
                $items[] = $item('first_in', 'First in', Ops::iso($day->first_in), null, $today);
            }
            if ($day->last_out) {
                $items[] = $item('last_out', 'Last out', Ops::iso($day->last_out), null, $today);
            }
            $items[] = $item('confidence', 'Attendance confidence', Confidence::describe($day->confidence)['label'], Confidence::describe($day->confidence)['meaning'], $today);
        } elseif ($state) {
            $items[] = $item('status', 'Status today', 'Not calculated yet', match ($state['state']) { 'working' => 'A shift is scheduled today.', 'off' => 'Not scheduled to work today.', default => 'No shift is assigned today.' }, $today);
        }
        if ($shift) {
            $items[] = $item('shift', 'Current shift', $shift->name, substr($shift->start_time, 0, 5).' - '.substr($shift->end_time, 0, 5).' ('.$shift->timezone.')', $today);
        }
        if ($items) {
            $out[] = ['key' => 'attendance_today', 'title' => 'Attendance today', 'order' => 40, 'source' => 'attendance', 'items' => $items];
        }

        $exceptions = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->orderByDesc('attendance_date')->limit(5)->get(['uuid', 'type', 'status', 'severity', 'attendance_date']);
        if ($exceptions->isNotEmpty()) {
            $out[] = ['key' => 'attendance_exceptions', 'title' => 'Recent attendance exceptions', 'order' => 41, 'source' => 'attendance',
                'items' => $exceptions->map(fn ($x) => $item('exception:'.$x->uuid, ExceptionType::title($x->type), ucfirst(str_replace('_', ' ', $x->status)), null, $x->attendance_date))->all()];
        }
        $regs = DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->orderByDesc('attendance_date')->limit(5)->get(['uuid', 'type', 'status', 'attendance_date']);
        if ($regs->isNotEmpty()) {
            $out[] = ['key' => 'attendance_regularizations', 'title' => 'Attendance regularizations', 'order' => 42, 'source' => 'attendance',
                'items' => $regs->map(fn ($r) => $item('regularization:'.$r->uuid, ucfirst(str_replace('_', ' ', $r->type)), ucfirst($r->status), null, $r->attendance_date))->all()];
        }
        $upcoming = [];
        for ($i = 1; $i <= 7 && count($upcoming) < 5; $i++) {
            $date = CarbonImmutable::parse($today)->addDays($i)->toDateString();
            $s = $this->coverage->shiftStates([(int) $employee->id], $date)[(int) $employee->id] ?? null;
            if ($s && $s['state'] === 'working' && $s['shift_id']) {
                $row = DB::table('shifts')->where('tenant_id', $tenant)->where('id', $s['shift_id'])->first(['name', 'start_time', 'end_time']);
                $upcoming[] = $item('shift:'.$date, $row->name ?? 'Shift', substr($row->start_time ?? '', 0, 5).' - '.substr($row->end_time ?? '', 0, 5), null, $date);
            }
        }
        if ($upcoming) {
            $out[] = ['key' => 'attendance_upcoming_shifts', 'title' => 'Upcoming shifts', 'order' => 43, 'source' => 'attendance', 'items' => $upcoming];
        }

        return $out;
    }
}
