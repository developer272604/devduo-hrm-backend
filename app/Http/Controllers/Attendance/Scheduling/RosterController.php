<?php

namespace App\Http\Controllers\Attendance\Scheduling;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\Roster;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftSwapRequest;
use App\Domain\Attendance\Scheduling\ConflictDetector;
use App\Domain\Attendance\Scheduling\Fail;
use App\Domain\Attendance\Scheduling\RosterService;
use App\Domain\Attendance\Scheduling\ScheduleView;
use App\Domain\Attendance\Scheduling\SwapService;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\People\PeopleController;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Rosters (grid, edit, publish), shift swaps, employee/team schedule views. */
class RosterController extends PeopleController
{
    public function __construct(private RosterService $rosters, private SwapService $swaps, private ScheduleView $views) {}

    // ---- rosters ---------------------------------------------------------------------------------------------------------------
    public function index(Request $r): JsonResponse
    {
        $p = Roster::query()->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('from'), fn ($q, $d) => $q->where('end_date', '>=', $d))
            ->when($r->query('to'), fn ($q, $d) => $q->where('start_date', '<=', $d))->orderByDesc('start_date')->orderByDesc('id')->paginate($this->perPage($r));

        return response()->json($p->through(fn ($x) => $this->rosters->present($x))->toArray());
    }

    public function store(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->rosters->present($this->rosters->create($r->all(), $r->user()))], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->rosters->present(Roster::byUuid($uuid))]);
    }

    public function update(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        if ($r->filled('name')) {
            $roster->update(['name' => trim((string) $r->input('name'))]);
        }

        return response()->json(['data' => $this->rosters->present($roster)]);
    }

    /** Daily / weekly / monthly grid. view=day|week|month with date, or explicit from/to. Paginated by employee. */
    public function grid(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        [$from, $to] = $this->window($r, $roster);
        $page = max(1, (int) $r->query('page', 1));
        $per = min(100, max(5, (int) $r->query('per_page', 50)));
        $found = $this->rosters->scopedEmployees($roster, $r->user(), $from, (array) $r->query('employee_uuids', []), $page, $per, $r->query('search'));
        $employees = $found['employees'];
        $cells = $this->rosters->grid($roster, $employees->pluck('id')->all(), $from, $to);
        $rows = $employees->map(fn ($e) => ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name, 'cells' => $cells[$e->id]])->values();

        return response()->json(['roster' => $this->rosters->present($roster), 'from' => $from, 'to' => $to, 'view' => $r->query('view', 'week'), 'data' => $rows,
            'total' => $found['total'], 'current_page' => $page, 'per_page' => $per, 'last_page' => max(1, (int) ceil($found['total'] / $per))]);
    }

    public function setEntry(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $employee = $this->editable($r, (string) $r->input('employee_uuid'));
        $type = (string) $r->input('entry_type', 'shift');
        $shift = $type === 'shift' ? Shift::query()->where('uuid', $r->input('shift_uuid'))->first() : null;
        $entry = $this->rosters->setEntry($roster, $employee, (string) $r->input('date'), $type, $shift, $r->input('reason'), $r->user(), $r->filled('expected_version') ? (int) $r->input('expected_version') : null);

        return response()->json(['data' => ['status' => $entry->exists ? $entry->status : 'unchanged', 'roster' => $this->rosters->present($roster->refresh())]]);
    }

    public function clearEntry(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $employee = $this->editable($r, (string) $r->input('employee_uuid'));
        $this->rosters->setEntry($roster, $employee, (string) $r->input('date'), 'cleared', null, $r->input('reason'), $r->user());

        return response()->json(['data' => $this->rosters->present($roster->refresh())]);
    }

    public function discard(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $this->rosters->discardDraft($roster, $this->editable($r, (string) $r->input('employee_uuid')), (string) $r->input('date'));

        return response()->json(['data' => $this->rosters->present($roster)]);
    }

    public function bulk(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $employees = $this->editableMany($r, (array) $r->input('employee_uuids', []));
        $type = (string) $r->input('entry_type', 'shift');
        $shift = $type === 'shift' ? Shift::query()->where('uuid', $r->input('shift_uuid'))->first() : null;
        $preview = $r->boolean('preview', true);
        $weekdays = $r->filled('weekdays') ? array_map('intval', (array) $r->input('weekdays')) : null;
        $res = $this->rosters->bulkAssign($roster, $employees, (string) $r->input('from'), (string) $r->input('to'), $weekdays, $type, $shift, $r->input('reason'), $r->user(), $preview, $r->boolean('acknowledge_warnings'));

        return response()->json(['data' => $res]);
    }

    public function copy(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $employees = $this->editableMany($r, (array) $r->input('employee_uuids', []));

        return response()->json(['data' => $this->rosters->copy($roster, $employees, (string) $r->input('source_from'), (string) $r->input('target_from'), (int) $r->input('days', 7), $r->user())]);
    }

    public function validateRoster(string $uuid): JsonResponse
    {
        $conflicts = $this->rosters->validate(Roster::byUuid($uuid));

        return response()->json(['data' => ['conflicts' => $conflicts, 'blocking' => ConflictDetector::hasBlocking($conflicts), 'count' => count($conflicts)]]);
    }

    public function publish(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $this->assertCanPublish($r, $roster);

        return response()->json(['data' => $this->rosters->publish($roster, $r->user(), $r->boolean('acknowledge_warnings'), $r->filled('expected_version') ? (int) $r->input('expected_version') : null)]);
    }

    public function unpublish(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $this->assertCanPublish($r, $roster);
        $this->rosters->unpublish($roster, $r->user(), $r->input('reason'));

        return response()->json(['data' => $this->rosters->present($roster->refresh())]);
    }

    public function changes(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->rosters->changedSince(Roster::byUuid($uuid))]);
    }

    public function history(Request $r, string $uuid): JsonResponse
    {
        $roster = Roster::byUuid($uuid);
        $employee = $this->editable($r, (string) $r->query('employee_uuid'));

        return response()->json(['data' => $this->rosters->history($roster, $employee, (string) $r->query('date'))]);
    }

    // ---- swaps -----------------------------------------------------------------------------------------------------------------
    public function swapIndex(Request $r): JsonResponse
    {
        $q = $this->swaps->inboxFilter(ShiftSwapRequest::query(), $r->user())->when($r->query('status'), fn ($x, $s) => $x->where('status', $s))->orderByDesc('id');
        $p = $q->paginate($this->perPage($r));

        return response()->json($p->through(fn ($s) => $this->swaps->present($s))->toArray());
    }

    public function swapStore(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return response()->json(['data' => $this->swaps->present($this->swaps->submit($me, $r->all(), $r->user()))], 201);
    }

    public function swapShow(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->swaps->present($this->visibleSwap($r, $uuid))]);
    }

    public function swapRespond(Request $r, string $uuid): JsonResponse
    {
        $swap = $this->visibleSwap($r, $uuid);

        return response()->json(['data' => $this->swaps->present($this->swaps->respond($swap, $r->boolean('accept'), $r->user(), $r->input('comment')))]);
    }

    public function swapDecide(Request $r, string $uuid): JsonResponse
    {
        $swap = $this->visibleSwap($r, $uuid);

        return response()->json(['data' => $this->swaps->present($this->swaps->decide($swap, $r->boolean('approve'), $r->user(), $r->input('comment')))]);
    }

    public function swapWithdraw(Request $r, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->swaps->present($this->swaps->withdraw($this->visibleSwap($r, $uuid), $r->user()))]);
    }

    // ---- schedule views -------------------------------------------------------------------------------------------------------
    public function mySchedule(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $today = \App\Domain\Tenancy\TenantClock::today($me->timezone);
        $from = (string) $r->query('from', $today);
        $to = (string) $r->query('to', CarbonImmutable::parse($from)->addDays(13)->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Fail::field('from', 'Use dates in YYYY-MM-DD format.');
        }

        return response()->json(['data' => $this->views->mine($me->id, $from, $to)]);
    }

    public function teamSchedule(Request $r): JsonResponse
    {
        $from = (string) $r->query('from', \App\Domain\Tenancy\TenantClock::today());
        $to = (string) $r->query('to', CarbonImmutable::parse($from)->addDays(6)->toDateString());
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays($to) > 31) {
            Fail::field('to', 'Choose a range of at most 31 days.');
        }
        $q = Employee::query()->join('people', 'people.id', '=', 'employees.person_id')->select('employees.id', 'people.display_name')->whereIn('employees.status', Employee::WORKING);
        app(DataScope::class)->constrain($q, $r->user(), 'attendance.team.view', 'employees.id');
        $p = $q->orderBy('people.display_name')->paginate(min(50, max(5, (int) $r->query('per_page', 25))));

        return response()->json(['data' => $this->views->team($p->pluck('id')->all(), $from, $to), 'from' => $from, 'to' => $to, 'total' => $p->total(), 'current_page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------------
    private function me(Request $r): Employee
    {
        $ids = app(DataScope::class)->ownEmployeeIds($r->user());
        $e = Employee::query()->whereIn('id', $ids ?: [0])->whereIn('status', Employee::WORKING)->orderByDesc('id')->first();
        abort_unless($e, 404, 'No active employment is linked to your login.');

        return $e;
    }

    private function visibleSwap(Request $r, string $uuid): ShiftSwapRequest
    {
        $s = ShiftSwapRequest::byUuid($uuid);
        abort_unless($this->swaps->inboxFilter(ShiftSwapRequest::query()->whereKey($s->id), $r->user())->exists() || $r->user()->allows('attendance.manage'), 404);

        return $s;
    }

    private function editable(Request $r, string $uuid): Employee
    {
        $e = Employee::query()->where('uuid', $uuid)->first();
        abort_unless($e && app(DataScope::class)->canAccess($r->user(), $e, 'attendance.roster.manage'), 404);

        return $e;
    }

    /** @return array<int,Employee> */
    private function editableMany(Request $r, array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if (! $uuids || count($uuids) > 500) {
            Fail::field('employee_uuids', 'Choose between 1 and 500 employees.');
        }
        $q = Employee::query()->whereIn('uuid', $uuids);
        app(DataScope::class)->constrain($q, $r->user(), 'attendance.roster.manage');
        $found = $q->get();
        abort_unless($found->count() === count($uuids), 404);

        return $found->all();
    }

    private function assertCanPublish(Request $r, Roster $roster): void
    {
        $scope = app(DataScope::class);
        $ids = DB::table('roster_entries')->where('tenant_id', $roster->tenant_id)->where('roster_id', $roster->id)->distinct()->pluck('employee_id');
        foreach ($ids as $id) {
            abort_unless($scope->canAccess($r->user(), (int) $id, 'attendance.roster.publish'), 403, 'This roster includes people outside the area you may publish for.');
        }
    }

    /** @return array{0:string,1:string} */
    private function window(Request $r, Roster $roster): array
    {
        $view = $r->query('view', 'week');
        $date = (string) $r->query('date', max($roster->start_date, min($roster->end_date, \App\Domain\Tenancy\TenantClock::today())));
        if ($r->filled('from') && $r->filled('to')) {
            [$from, $to] = [(string) $r->query('from'), (string) $r->query('to')];
        } else {
            $d = CarbonImmutable::parse($date);
            [$from, $to] = match ($view) {
                'day' => [$d->toDateString(), $d->toDateString()],
                'month' => [$d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString()],
                default => [$d->startOfWeek()->toDateString(), $d->endOfWeek()->toDateString()],
            };
        }
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays($to) > 62) {
            Fail::field('to', 'Choose a window of at most 62 days.');
        }

        return [$from, $to];
    }
}
