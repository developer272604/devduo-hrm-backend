<?php

namespace App\Http\Controllers\Leave\Requests;

use App\Domain\Leave\CompOff\CompOffService;
use App\Domain\Leave\Models\LeaveCompOffClaim;
use App\Domain\Leave\Models\LeaveCompOffCredit;
use App\Domain\Leave\Requests\LeaveApprovers;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestPresenter;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;

class CompOffController extends LeaveController
{
    public function __construct(LeaveSupport $support, LeaveApprovers $approvers, RequestPresenter $presenter, private CompOffService $compOff)
    {
        parent::__construct($support, $approvers, $presenter);
    }

    /** scope=mine (default) | team (leave.compoff.manage within data scope). */
    public function index(Request $r)
    {
        $user = $r->user();
        $q = LeaveCompOffCredit::query()->orderByDesc('id');
        if ($r->input('scope', 'mine') === 'mine') {
            $q->whereIn('employee_id', $this->support->scope()->ownEmployeeIds($user) ?: [0]);
        } else {
            abort_unless($user->allows('leave.compoff.manage'), 403, 'You do not have access to that view.');
            $this->support->scope()->constrain($q, $user, 'leave.compoff.manage', 'leave_comp_off_credits.employee_id');
        }
        if ($s = $r->input('status')) {
            $q->where('status', $s);
        }

        return response()->json($this->paged($q->paginate($this->perPage($r)), fn ($c) => $this->present($c)));
    }

    /** Body: source_type (overtime|holiday_work|rest_day_work|authorized), source_id (approved overtime uuid), work_date, units (authorized), note, employee (HR). */
    public function store(Request $r)
    {
        $employee = $this->subject($r);
        $credit = $this->compOff->earn($r->user(), $employee, $r->only(['source_type', 'source_id', 'work_date', 'units', 'note', 'leave_type']));

        return response()->json(['data' => $this->present($credit)], 201);
    }

    public function approve(Request $r, string $uuid)
    {
        return response()->json(['data' => $this->present($this->compOff->decide($r->user(), $this->credit($r, $uuid), 'approve', $r->input('comment')))]);
    }

    public function reject(Request $r, string $uuid)
    {
        $r->validate(['comment' => 'required|string|max:1000']);

        return response()->json(['data' => $this->present($this->compOff->decide($r->user(), $this->credit($r, $uuid), 'reject', $r->input('comment')))]);
    }

    /** Body: a normal leave request (start_date, end_date, portion, ...); the leave type is the credit's. */
    public function claim(Request $r, string $uuid)
    {
        $credit = $this->credit($r, $uuid);
        $employee = Employee::query()->findOrFail($credit->employee_id);
        abort_unless($this->support->scope()->isSelf($r->user(), $employee), 404, 'That comp-off was not found.');
        [$request, $claim] = $this->compOff->claim($r->user(), $employee, $credit, $r->except(['leave_type', 'documents']), $this->idempotencyKey($r));

        return response()->json(['data' => ['request' => $this->presenter->summary($request, $r->user()), 'claim' => ['uuid' => $claim->uuid, 'units' => Units::normalize($claim->getRawOriginal('units')), 'status' => $this->compOff->claimStatus($claim)]]], 201);
    }

    public function trace(Request $r, string $uuid)
    {
        return response()->json(['data' => $this->compOff->trace($this->credit($r, $uuid))]);
    }

    private function credit(Request $r, string $uuid): LeaveCompOffCredit
    {
        $credit = LeaveCompOffCredit::query()->where('uuid', $uuid)->firstOrFail();
        $employee = Employee::query()->findOrFail($credit->employee_id);
        $scope = $this->support->scope();
        $user = $r->user();
        abort_unless($scope->isSelf($user, $employee) || ($user->allows('leave.compoff.manage') && $scope->canAccess($user, $employee, 'leave.compoff.manage')) || ($user->allows('leave.approve') && $scope->isManagerOf($user, $employee)), 404, 'That comp-off was not found.');

        return $credit;
    }

    private function present(LeaveCompOffCredit $c): array
    {
        $employee = Employee::query()->with('person')->find($c->employee_id);
        $claims = LeaveCompOffClaim::query()->where('credit_id', $c->id)->get();

        return ['uuid' => $c->uuid, 'employee' => $this->presenter->employee($employee), 'source_type' => $c->source_type, 'source_id' => $c->source_id, 'work_date' => $c->work_date, 'units' => Units::normalize($c->getRawOriginal('units')),
            'remaining' => $c->status === 'approved' ? $this->compOff->remaining($c) : null, 'status' => $c->status, 'valid_from' => $c->valid_from, 'expires_on' => $c->expires_on, 'note' => $c->note,
            'claims' => $claims->map(fn ($x) => ['uuid' => $x->uuid, 'units' => Units::normalize($x->getRawOriginal('units')), 'status' => $this->compOff->claimStatus($x)])->all()];
    }
}
