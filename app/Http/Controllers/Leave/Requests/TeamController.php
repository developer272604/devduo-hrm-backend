<?php

namespace App\Http\Controllers\Leave\Requests;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Requests\LeaveApprovers;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestPresenter;
use App\Domain\Leave\Requests\TeamAvailability;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TeamController extends LeaveController
{
    public function __construct(LeaveSupport $support, LeaveApprovers $approvers, RequestPresenter $presenter, private TeamAvailability $team)
    {
        parent::__construct($support, $approvers, $presenter);
    }

    /** Query: from, to (default this month, max 62 days), include_pending (default true), employees[] (uuids). */
    public function calendar(Request $r)
    {
        [$from, $to] = $this->range($r);

        return response()->json(['data' => $this->team->calendar($r->user(), $from, $to, $r->boolean('include_pending', true), true, $r->input('employees')), 'meta' => ['from' => $from, 'to' => $to]]);
    }

    /** Query: date (default today). */
    public function availability(Request $r)
    {
        $r->validate(['date' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->team->availability($r->user(), $r->input('date', TenantClock::today()), $r->boolean('include_pending', true))]);
    }

    /** The employee's own month: their leave days plus their holidays. */
    public function mine(Request $r)
    {
        [$from, $to] = $this->range($r);
        $ids = $this->support->scope()->ownEmployeeIds($r->user());
        $days = LeaveRequestSegment::query()->whereIn('employee_id', $ids ?: [0])->whereBetween('leave_date', [$from, $to])->where('status', 'active')->where('charged', true)
            ->whereIn('request_id', LeaveRequest::query()->whereIn('status', ['pending', 'approved', 'partially_cancelled'])->select('id'))->orderBy('leave_date')->get();
        $requests = LeaveRequest::query()->whereIn('id', $days->pluck('request_id')->unique())->get()->keyBy('id');

        return response()->json(['data' => ['leave' => $days->map(fn ($s) => ['date' => $s->leave_date, 'portion' => $s->portion, 'request' => $requests[$s->request_id]->uuid, 'status' => $requests[$s->request_id]->status === 'pending' ? 'pending' : 'approved',
            'type' => $this->presenter->type($s->leave_type_id, true)['name']])->all(), 'holidays' => $this->team->ownHolidays($r->user(), $from, $to)], 'meta' => ['from' => $from, 'to' => $to]]);
    }

    private function range(Request $r): array
    {
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = $r->input('from', CarbonImmutable::parse(TenantClock::today())->startOfMonth()->toDateString());
        $to = $r->input('to', CarbonImmutable::parse($from)->endOfMonth()->toDateString());
        abort_if($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 62, 422, 'Choose a range of at most 62 days.');

        return [$from, $to];
    }
}
