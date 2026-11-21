<?php

namespace App\Http\Controllers\Attendance\Scheduling;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Models\Roster;
use App\Domain\Attendance\Models\RosterEntry;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Attendance\Scheduling\ConflictDetector;
use App\Domain\Attendance\Scheduling\DatabaseScheduleResolver;
use App\Domain\Attendance\Scheduling\RosterService;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShiftBidController extends Controller
{
    public function __construct(private RosterService $rosters) {}

    private function openings(Request $request): Builder
    {
        return DB::table('roster_shift_openings')->where('roster_shift_openings.tenant_id', $request->user()->tenant_id);
    }

    public function create(Request $request, string $uuid)
    {
        $input = $request->validate(['shift_uuid' => 'required|uuid', 'date' => 'required|date_format:Y-m-d']);
        $user = $request->user();

        return DB::transaction(function () use ($uuid, $user, $input) {
            $roster = Roster::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $date = $input['date'];
            abort_if($date < TenantClock::today() || $date < $roster->start_date || $date > $roster->end_date, 422, 'Choose an upcoming date within the roster.');
            $shift = Shift::query()->where('uuid', $input['shift_uuid'])->where('active', true)->firstOrFail();
            abort_if(DB::table('roster_shift_openings')->where('roster_id', $roster->id)->where('shift_id', $shift->id)
                ->where('work_date', $date)->where('status', 'open')->exists(), 409, 'An opening already exists for this roster, shift and date.');
            $staff = $this->rosters->scopedEmployees($roster, $user, $date, [], 1, 500);
            abort_if($staff['total'] > 500, 422, 'Use a roster with at most 500 people in your scope.');
            $ids = $staff['employees']->reject(fn ($employee) => $employee->person_id === null
                || ! in_array($employee->status, ['active', 'probation', 'notice_period'], true)
                || $employee->joined_on?->toDateString() > $date
                || ($employee->separated_on && $employee->separated_on->toDateString() < $date))->pluck('id');
            $own = app(DataScope::class)->ownEmployeeIds($user);
            $ids = $ids->diff($own);
            abort_if($ids->isEmpty(), 422, 'There are no eligible roster members in your scope.');
            $publicId = (string) Str::uuid();
            $opening = DB::table('roster_shift_openings')->insertGetId([
                'tenant_id' => $user->tenant_id, 'roster_id' => $roster->id, 'shift_id' => $shift->id,
                'created_by' => $user->id, 'uuid' => $publicId, 'work_date' => $date, 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($ids->chunk(100) as $chunk) {
                DB::table('roster_shift_bids')->insert($chunk->map(fn ($id) => ['opening_id' => $opening, 'employee_id' => $id])->values()->all());
            }
            Audit::record('shift_opening.created', 'attendance', 'shift_opening:'.$publicId);

            return response()->json(['uuid' => $publicId], 201);
        }, 3);
    }

    public function mine(Request $request)
    {
        $employee = Ops::ownEmployee($request->user());
        if (! $employee) {
            return ['data' => [], 'last_page' => 1];
        }

        return $this->openings($request)
            ->join('roster_shift_bids as bids', 'bids.opening_id', '=', 'roster_shift_openings.id')
            ->join('shifts', 'shifts.id', '=', 'roster_shift_openings.shift_id')
            ->where('bids.employee_id', $employee->id)->where('work_date', '>=', TenantClock::today())
            ->orderBy('work_date')->orderBy('roster_shift_openings.id')
            ->paginate(20, ['roster_shift_openings.uuid', 'roster_shift_openings.work_date', 'roster_shift_openings.status', 'awarded_employee_id', 'bids.bid_at', 'shifts.name as shift'])
            ->through(fn ($row) => [
                'uuid' => $row->uuid, 'work_date' => $row->work_date, 'status' => $row->status, 'bid_at' => $row->bid_at,
                'shift' => $row->shift, 'awarded_to_me' => (int) $row->awarded_employee_id === (int) $employee->id,
            ]);
    }

    public function bid(Request $request, string $uuid)
    {
        $input = $request->validate(['apply' => 'required|boolean']);
        $employee = Ops::ownEmployee($request->user());
        abort_unless($employee, 404);

        return DB::transaction(function () use ($request, $uuid, $employee, $input) {
            $opening = $this->openings($request)->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($opening, 404);
            $query = DB::table('roster_shift_bids')->where('opening_id', $opening->id)->where('employee_id', $employee->id);
            $bid = (clone $query)->first();
            abort_unless($bid, 404);
            abort_if($opening->status !== 'open' || $opening->work_date < TenantClock::today(), 409, 'This opening is closed.');
            abort_if($input['apply'] && ! in_array($employee->status, ['active', 'probation', 'notice_period'], true), 422, 'You are no longer eligible to apply.');
            if ((bool) $input['apply'] !== ($bid->bid_at !== null)) {
                $query->update(['bid_at' => $input['apply'] ? now() : null]);
                Audit::record($input['apply'] ? 'shift_opening.bid' : 'shift_opening.bid_withdrawn', 'attendance', 'shift_opening:'.$uuid);
            }

            return response()->noContent();
        }, 3);
    }

    public function managed(Request $request, string $uuid)
    {
        $roster = Roster::byUuid($uuid);

        return $this->openings($request)->where('roster_id', $roster->id)->where('created_by', $request->user()->id)
            ->join('shifts', 'shifts.id', '=', 'roster_shift_openings.shift_id')
            ->latest('roster_shift_openings.id')->paginate(20, ['roster_shift_openings.uuid', 'roster_shift_openings.work_date', 'roster_shift_openings.status', 'shifts.name as shift']);
    }

    public function bids(Request $request, string $uuid)
    {
        $opening = $this->openings($request)->where('uuid', $uuid)->where('created_by', $request->user()->id)->first();
        abort_unless($opening, 404);
        $query = Employee::query()->join('people', 'people.id', '=', 'employees.person_id')
            ->join('roster_shift_bids as bids', 'bids.employee_id', '=', 'employees.id')
            ->where('bids.opening_id', $opening->id)->whereNotNull('bids.bid_at');
        app(DataScope::class)->constrain($query, $request->user(), 'attendance.roster.manage');

        return $query->orderBy('bids.bid_at')->orderBy('employees.id')
            ->paginate(20, ['employees.uuid', 'people.display_name as name', 'bids.bid_at']);
    }

    public function decide(Request $request, string $uuid)
    {
        $data = $request->validate(['action' => 'required|in:award,cancel', 'employee_uuid' => 'required_if:action,award|nullable|uuid']);
        $user = $request->user();

        return DB::transaction(function () use ($request, $uuid, $data, $user) {
            $opening = $this->openings($request)->where('uuid', $uuid)->where('created_by', $user->id)->lockForUpdate()->first();
            abort_unless($opening, 404);
            abort_if($opening->status !== 'open', 409, 'This opening is already closed.');
            $state = ['status' => 'cancelled', 'updated_at' => now()];
            if ($data['action'] === 'award') {
                $employee = Employee::query()->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
                $scope = app(DataScope::class);
                abort_unless($scope->canAccess($user, $employee, 'attendance.roster.manage'), 404);
                abort_if($scope->isSelf($user, $employee), 403);
                abort_unless(DB::table('roster_shift_bids')->where('opening_id', $opening->id)
                    ->where('employee_id', $employee->id)->whereNotNull('bid_at')->exists(), 404);
                $date = $opening->work_date;
                abort_if($date < TenantClock::today() || ! in_array($employee->status, ['active', 'probation', 'notice_period'], true)
                    || $employee->joined_on?->toDateString() > $date
                    || ($employee->separated_on && $employee->separated_on->toDateString() < $date), 422, 'The employee is no longer eligible for this date.');
                $roster = Roster::query()->whereKey($opening->roster_id)->lockForUpdate()->firstOrFail();
                $shift = Shift::query()->whereKey($opening->shift_id)->where('active', true)->firstOrFail();
                abort_if(RosterEntry::query()->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'draft')
                    ->where(fn ($draft) => $draft->where('entry_type', 'shift')->orWhere('roster_id', '!=', $roster->id))->exists()
                    || app(DatabaseScheduleResolver::class)->resolve($employee->id, $date)->kind === 'working', 409, 'The employee already has a shift or draft for this date.');
                abort_if(app(LeaveDataProvider::class)->coverage($employee->id, $date) !== null, 422, 'The employee has approved leave on this date.');
                $conflicts = app(ConflictDetector::class)->detect([$employee->id], $date, $date, [
                    $employee->id => [$date => ['entry_type' => 'shift', 'shift_id' => $shift->id]],
                ]);
                abort_if(ConflictDetector::hasBlocking($conflicts), 422, 'The proposed award conflicts with scheduling rules.');
                $this->rosters->setEntry($roster, $employee, $date, 'shift', $shift, 'Awarded through shift bidding.', $user);
                $state = ['status' => 'awarded', 'updated_at' => now(), 'awarded_employee_id' => $employee->id];
            }
            $this->openings($request)->where('id', $opening->id)->update($state);
            Audit::record('shift_opening.'.$state['status'], 'attendance', 'shift_opening:'.$uuid);

            return response()->noContent();
        }, 3);
    }
}
