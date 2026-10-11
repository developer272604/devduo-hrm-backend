<?php

namespace App\Http\Controllers\Leave\Requests;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Requests\DecisionService;
use App\Domain\Leave\Requests\LeaveApprovers;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestPresenter;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;

class ApprovalController extends LeaveController
{
    public function __construct(LeaveSupport $support, LeaveApprovers $approvers, RequestPresenter $presenter, private DecisionService $decisions)
    {
        parent::__construct($support, $approvers, $presenter);
    }

    /** Pending requests the signed-in user may decide right now (including as a delegate). */
    public function inbox(Request $r)
    {
        $user = $r->user();
        $rows = LeaveRequest::query()->where('status', 'pending')->orderBy('start_date')->orderBy('id')->limit(300)->get()
            ->filter(fn (LeaveRequest $x) => $this->approvers->canDecide($user, $x, Employee::query()->findOrFail($x->employee_id)))->values();

        return response()->json(['data' => $rows->map(fn ($x) => $this->presenter->summary($x, $user))->all(), 'meta' => ['total' => $rows->count()]]);
    }

    public function approve(Request $r, string $uuid)
    {
        $request = $this->decidable($r, $uuid);
        $r->validate(['comment' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->presenter->detail($this->decisions->approve($r->user(), $request, $r->input('comment')), $r->user())]);
    }

    public function reject(Request $r, string $uuid)
    {
        $request = $this->decidable($r, $uuid);
        $r->validate(['comment' => 'required|string|max:1000']);

        return response()->json(['data' => $this->presenter->detail($this->decisions->reject($r->user(), $request, $r->input('comment')), $r->user())]);
    }

    private function decidable(Request $r, string $uuid): LeaveRequest
    {
        $request = $this->request($uuid);
        abort_unless($this->approvers->canDecide($r->user(), $request, Employee::query()->findOrFail($request->employee_id)), 404, 'That leave request was not found.');

        return $request;
    }
}
