<?php

namespace App\Http\Controllers\Attendance\Scheduling;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\PayrollGroup;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftRotation;
use App\Domain\Attendance\Models\WorkWeek;
use App\Domain\Attendance\Scheduling\ComplianceRules;
use App\Domain\Attendance\Scheduling\Fail;
use App\Domain\Attendance\Scheduling\HolidayService;
use App\Domain\Attendance\Scheduling\ImpactPreview;
use App\Domain\Attendance\Scheduling\RotationService;
use App\Domain\Attendance\Scheduling\ScheduleAssignmentService;
use App\Domain\Attendance\Scheduling\ShiftService;
use App\Domain\Attendance\Scheduling\WorkWeekService;
use App\Domain\Audit\Audit;
use App\Domain\Access\DataScope;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Work weeks, holiday calendars, shifts, rotations, schedule assignments, payroll groups, compliance settings, impact preview. */
class SchedulingConfigController extends PeopleController
{
    public function __construct(private ShiftService $shifts, private WorkWeekService $weeks, private HolidayService $holidays, private RotationService $rotations,
        private ScheduleAssignmentService $assignments, private ComplianceRules $compliance) {}

    // ---- shifts -------------------------------------------------------------------------------------------------------------
    public function shiftIndex(Request $r): JsonResponse
    {
        $q = Shift::query()->with('segments')->when($r->boolean('active_only'), fn ($x) => $x->where('active', true))->when($r->query('kind'), fn ($x, $k) => $x->where('kind', $k))
            ->when($r->query('search'), fn ($x, $s) => $x->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))->orderBy('name');
        $p = $q->paginate($this->perPage($r, 50));

        return response()->json($p->through(fn ($s) => $this->shifts->present($s))->toArray());
    }

    public function shiftShow(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->shifts->present(Shift::byUuid($uuid)->load('segments'), true)]);
    }

    public function shiftStore(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->shifts->present($this->shifts->create($r->all()))], 201);
    }

    public function shiftUpdate(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->shifts->present($this->shifts->update(Shift::byUuid($uuid), $r->all()))]);
    }

    public function shiftDestroy(string $uuid): JsonResponse
    {
        $this->shifts->delete(Shift::byUuid($uuid));

        return response()->json(['message' => 'Shift deleted.']);
    }

    // ---- work weeks ---------------------------------------------------------------------------------------------------------
    public function weekIndex(Request $r): JsonResponse
    {
        $rows = WorkWeek::query()->with('days')->orderBy('code')->orderByDesc('version')->get();

        return response()->json(['data' => $rows->map(fn ($w) => $this->weeks->present($w))->values()]);
    }

    public function weekShow(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->weeks->present(WorkWeek::byUuid($uuid))]);
    }

    public function weekStore(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->weeks->present($this->weeks->create($r->all(), $r->user()))], 201);
    }

    public function weekUpdate(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->weeks->present($this->weeks->update(WorkWeek::byUuid($uuid), $r->all(), $r->user()))]);
    }

    public function weekVersion(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->weeks->present($this->weeks->newVersion(WorkWeek::byUuid($uuid), $r->all(), $r->user()))], 201);
    }

    public function weekDestroy(string $uuid): JsonResponse
    {
        $this->weeks->delete(WorkWeek::byUuid($uuid));

        return response()->json(['message' => 'Work week deleted.']);
    }

    // ---- holiday calendars ----------------------------------------------------------------------------------------------------
    public function calendarIndex(Request $r): JsonResponse
    {
        return response()->json(['data' => HolidayCalendar::query()->orderBy('name')->get()->map(fn ($c) => $this->holidays->present($c))->values()]);
    }

    public function calendarShow(Request $r, string $uuid): JsonResponse
    {
        $c = HolidayCalendar::byUuid($uuid);
        $year = (int) $r->query('year', 0);
        $h = Holiday::query()->where('calendar_id', $c->id)->when($year, fn ($q) => $q->whereBetween('date', ["$year-01-01", "$year-12-31"]))->orderBy('date')->get();

        return response()->json(['data' => $this->holidays->present($c) + ['holidays' => $h->map(fn ($x) => $this->holidays->presentHoliday($x))->values()]]);
    }

    public function calendarStore(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->holidays->present($this->holidays->createCalendar($r->all()))], 201);
    }

    public function calendarUpdate(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->holidays->present($this->holidays->updateCalendar(HolidayCalendar::byUuid($uuid), $r->all()))]);
    }

    public function calendarDestroy(string $uuid): JsonResponse
    {
        $this->holidays->deleteCalendar(HolidayCalendar::byUuid($uuid));

        return response()->json(['message' => 'Calendar deleted.']);
    }

    public function calendarCopy(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->holidays->present($this->holidays->copyCalendar(HolidayCalendar::byUuid($uuid), $r->all()))], 201);
    }

    public function holidayStore(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->holidays->presentHoliday($this->holidays->addHoliday(HolidayCalendar::byUuid($uuid), $r->all(), $r->user()))], 201);
    }

    public function holidayUpdate(Request $r, string $uuid, int $id): JsonResponse
    {
        $c = HolidayCalendar::byUuid($uuid);

        return response()->json(['data' => $this->holidays->presentHoliday($this->holidays->updateHoliday($c, Holiday::query()->where('calendar_id', $c->id)->findOrFail($id), $r->all(), $r->user()))]);
    }

    public function holidayDestroy(Request $r, string $uuid, int $id): JsonResponse
    {
        $c = HolidayCalendar::byUuid($uuid);
        $this->holidays->removeHoliday($c, Holiday::query()->where('calendar_id', $c->id)->findOrFail($id), $r->user());

        return response()->json(['message' => 'Holiday removed.']);
    }

    public function holidayGenerate(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->holidays->generateRecurring(HolidayCalendar::byUuid($uuid), (int) $r->input('year'), $r->user())]);
    }

    /** Holidays an employee gets in a range (Leave consumes this). Own record, or within attendance.team.view / roster scope. */
    public function employeeHolidays(Request $r, string $uuid): JsonResponse
    {
        $e = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class);
        abort_unless($scope->isSelf($r->user(), $e) || $scope->canAccess($r->user(), $e, 'attendance.team.view') || $scope->canAccess($r->user(), $e, 'attendance.roster.manage'), 404);
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || \Carbon\CarbonImmutable::parse($from)->diffInDays($to) > 400) {
            Fail::field('from', 'Provide from and to dates (YYYY-MM-DD), at most about a year apart.');
        }

        return response()->json(['data' => $this->holidays->forEmployee($e->id, $from, $to)]);
    }

    // ---- rotations -------------------------------------------------------------------------------------------------------------
    public function rotationIndex(Request $r): JsonResponse
    {
        return response()->json(['data' => ShiftRotation::query()->with('patterns')->orderBy('name')->get()->map(fn ($x) => $this->rotations->present($x))->values()]);
    }

    public function rotationShow(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->rotations->present(ShiftRotation::byUuid($uuid))]);
    }

    public function rotationStore(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->rotations->present($this->rotations->create($r->all()))], 201);
    }

    public function rotationUpdate(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->rotations->present($this->rotations->update(ShiftRotation::byUuid($uuid), $r->all()))]);
    }

    public function rotationDestroy(string $uuid): JsonResponse
    {
        $this->rotations->delete(ShiftRotation::byUuid($uuid));

        return response()->json(['message' => 'Rotation deleted.']);
    }

    // ---- assignments (kind from route default: shift | work_week | holiday) ----------------------------------------------------
    public function assignmentIndex(Request $r, string $kind): JsonResponse
    {
        $config = ScheduleAssignmentService::KINDS[$kind];
        $q = $config['model']::query()->when($r->query('target_type'), fn ($x, $t) => $x->where('target_type', $t))->when($r->query('target_id'), fn ($x, $t) => $x->where('target_id', $t))
            ->when($r->query('active_on'), fn ($x, $d) => $x->where('effective_from', '<=', $d)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $d)))->orderByDesc('effective_from')->orderByDesc('id');
        $p = $q->paginate($this->perPage($r, 50));

        return response()->json($p->through(fn ($a) => $this->assignments->present($kind, $a))->toArray());
    }

    public function assignmentStore(Request $r, string $kind): JsonResponse
    {
        return response()->json(['data' => $this->assignments->create($kind, $r->all(), $r->user(), $r->boolean('supersede'))], 201);
    }

    public function assignmentUpdate(Request $r, string $kind, string $id): JsonResponse
    {
        return response()->json(['data' => $this->assignments->update($kind, $this->assignment($kind, $id), $r->all(), $r->user())]);
    }

    public function assignmentDestroy(Request $r, string $kind, string $id): JsonResponse
    {
        $this->assignments->delete($kind, $this->assignment($kind, $id), $r->user());

        return response()->json(['message' => 'Assignment removed.']);
    }

    private function assignment(string $kind, string $id)
    {
        $model = ScheduleAssignmentService::KINDS[$kind]['model'];

        return $kind === 'shift' ? $model::byUuid($id) : $model::query()->findOrFail($id);
    }

    // ---- payroll groups -------------------------------------------------------------------------------------------------------
    public function payrollIndex(): JsonResponse
    {
        return response()->json(['data' => PayrollGroup::query()->orderBy('name')->get()]);
    }

    public function payrollSave(Request $r, ?int $id = null): JsonResponse
    {
        $row = $id ? PayrollGroup::query()->findOrFail($id) : null;
        $code = strtoupper(trim((string) $r->input('code', $row->code ?? '')));
        $name = trim((string) $r->input('name', $row->name ?? ''));
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Give the payroll group a name.';
        }
        if (! preg_match('/^[A-Z0-9_-]{1,40}$/', $code)) {
            $errors['code'] = 'Code must be 1-40 letters, numbers, dashes or underscores.';
        } elseif (PayrollGroup::query()->where('code', $code)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors['code'] = "A payroll group with code $code already exists.";
        }
        $cutoff = $r->input('cutoff_day', $row->cutoff_day ?? null);
        if ($cutoff !== null && (! is_numeric($cutoff) || $cutoff < 1 || $cutoff > 31)) {
            $errors['cutoff_day'] = 'Cutoff day must be between 1 and 31.';
        }
        if ($errors) {
            Fail::fields($errors);
        }
        $data = ['name' => $name, 'code' => $code, 'cutoff_day' => $cutoff, 'active' => (bool) $r->input('active', $row->active ?? true)];
        $row ? $row->update($data) : $row = PayrollGroup::create($data);
        Audit::record($id ? 'updated' : 'created', 'attendance', 'payroll_group:'.$row->id, ['code' => $code]);

        return response()->json(['data' => $row], $id ? 200 : 201);
    }

    public function payrollDestroy(int $id): JsonResponse
    {
        $row = PayrollGroup::query()->findOrFail($id);
        if (\Illuminate\Support\Facades\DB::table('attendance_locks')->where('tenant_id', $row->tenant_id)->where('scope_type', 'payroll_group')->where('scope_id', (string) $id)->exists()) {
            Fail::business('payroll_group_in_use', 'Attendance locks reference this payroll group. Mark it inactive instead.', 409);
        }
        $row->delete();
        Audit::record('deleted', 'attendance', 'payroll_group:'.$id, []);

        return response()->json(['message' => 'Payroll group deleted.']);
    }

    // ---- compliance + impact --------------------------------------------------------------------------------------------------
    public function complianceShow(): JsonResponse
    {
        return response()->json(['data' => $this->compliance->show()]);
    }

    public function complianceSave(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->compliance->save($r->all())]);
    }

    public function impact(Request $r): JsonResponse
    {
        return response()->json(['data' => app(ImpactPreview::class)->preview((string) $r->input('type'), (array) $r->input('change', []), $r->user())]);
    }
}
