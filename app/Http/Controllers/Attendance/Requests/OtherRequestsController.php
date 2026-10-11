<?php

namespace App\Http\Controllers\Attendance\Requests;

use App\Domain\Attendance\Models\ApprovalDelegation;
use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\AttendanceBulkRun;
use App\Domain\Attendance\Models\OnDutyRequest;
use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Requests\AdjustmentService;
use App\Domain\Attendance\Requests\BulkRunner;
use App\Domain\Attendance\Requests\OnDutyService;
use App\Domain\Attendance\Requests\OvertimeService;
use App\Domain\Attendance\Requests\RegularizationService;
use App\Domain\Attendance\Requests\RequestSupport;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Overtime, on-duty, adjustments, delegations, approvals inbox and bulk endpoints (thin: all rules live in the services). */
class OtherRequestsController extends RequestController
{
    // ---- overtime ------------------------------------------------------------------------------
    public function overtimeIndex(Request $r): JsonResponse
    {
        $q = OvertimeRequest::query()->with('employee.person')->orderByDesc('id');

        return response()->json($this->scoped($r, $q, 'attendance_overtime', 'overtime_requests.employee_id', fn ($x) => $this->presenter->overtime($x, $r->user())));
    }

    public function overtimeStore(Request $r, OvertimeService $s): JsonResponse
    {
        [$x, $created] = $s->submit($r->user(), $this->subject($r, 'attendance.overtime.approve'), $r->only(['work_date', 'mode', 'ot_type', 'requested_minutes', 'reason']), $this->idempotencyKey($r));

        return response()->json(['data' => $this->presenter->overtime($x, $r->user())], 201);
    }

    public function overtimeApprove(Request $r, string $uuid, OvertimeService $s): JsonResponse
    {
        $x = $s->decide($r->user(), OvertimeRequest::byUuid($uuid), 'approve', $r->filled('approved_minutes') ? (int) $r->input('approved_minutes') : null, $r->input('comment'));

        return response()->json(['data' => $this->presenter->overtime($x, $r->user())]);
    }

    public function overtimeReject(Request $r, string $uuid, OvertimeService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->overtime($s->decide($r->user(), OvertimeRequest::byUuid($uuid), 'reject', null, $r->input('comment')), $r->user())]);
    }

    public function overtimeWithdraw(Request $r, string $uuid, OvertimeService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->overtime($s->withdraw($r->user(), OvertimeRequest::byUuid($uuid)), $r->user())]);
    }

    // ---- on duty -------------------------------------------------------------------------------
    public function dutyIndex(Request $r): JsonResponse
    {
        $q = OnDutyRequest::query()->with('employee.person')->orderByDesc('id');

        return response()->json($this->scoped($r, $q, 'attendance_on_duty', 'on_duty_requests.employee_id', fn ($x) => $this->presenter->onDuty($x, $r->user())));
    }

    public function dutyStore(Request $r, OnDutyService $s): JsonResponse
    {
        $r->validate(['attachment' => 'nullable|file']);
        [$x, $created] = $s->submit($r->user(), $this->subject($r, 'attendance.approve'), $r->only(['type', 'start_date', 'end_date', 'full_day', 'start_time', 'end_time', 'timezone', 'reason', 'location_text']), $r->file('attachment'), $this->idempotencyKey($r));

        return response()->json(['data' => $this->presenter->onDuty($x, $r->user()), 'idempotent_replay' => ! $created], $created ? 201 : 200);
    }

    public function dutyApprove(Request $r, string $uuid, OnDutyService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->onDuty($s->decide($r->user(), OnDutyRequest::byUuid($uuid), 'approve', $r->input('comment')), $r->user())]);
    }

    public function dutyReject(Request $r, string $uuid, OnDutyService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->onDuty($s->decide($r->user(), OnDutyRequest::byUuid($uuid), 'reject', $r->input('comment')), $r->user())]);
    }

    public function dutyWithdraw(Request $r, string $uuid, OnDutyService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->onDuty($s->withdraw($r->user(), OnDutyRequest::byUuid($uuid)), $r->user())]);
    }

    public function dutyAttachment(Request $r, string $uuid)
    {
        $x = OnDutyRequest::byUuid($uuid);
        abort_unless($this->mayView($r, $x->employee, 'on_duty', $x->start_date) && $x->attachment_path && Storage::disk(RequestSupport::DISK)->exists($x->attachment_path), 404, 'There is no attachment on this request.');

        return Storage::disk(RequestSupport::DISK)->download($x->attachment_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    // ---- adjustments ---------------------------------------------------------------------------
    public function adjustmentIndex(Request $r): JsonResponse
    {
        $q = AttendanceAdjustment::query()->with('employee.person')->orderByDesc('id');
        $this->support->scope()->constrain($q, $r->user(), 'attendance.adjust', 'attendance_adjustments.employee_id');
        $r->filled('status') && $q->where('status', (string) $r->input('status'));
        if ($r->filled('employee')) {
            $q->where('employee_id', $this->support->employee($r->user(), (string) $r->input('employee'), 'attendance.adjust', false)->id);
        }

        return response()->json($this->paged($q->paginate($this->perPage($r)), fn ($x) => $this->presenter->adjustment($x, $r->user())));
    }

    public function adjustmentStore(Request $r, AdjustmentService $s): JsonResponse
    {
        $employee = $this->support->employee($r->user(), (string) $r->input('employee'), 'attendance.adjust', false);
        $a = $s->create($r->user(), $employee, $r->only(['date', 'type', 'reason', 'status', 'note', 'direction', 'at', 'event_uuid']));

        return response()->json(['data' => $this->presenter->adjustment($a, $r->user())], 201);
    }

    public function adjustmentApprove(Request $r, string $uuid, AdjustmentService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->adjustment($s->approve($r->user(), AttendanceAdjustment::byUuid($uuid), $r->input('comment')), $r->user())]);
    }

    public function adjustmentReject(Request $r, string $uuid, AdjustmentService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->adjustment($s->reject($r->user(), AttendanceAdjustment::byUuid($uuid), $r->input('comment')), $r->user())]);
    }

    public function adjustmentRevert(Request $r, string $uuid, AdjustmentService $s): JsonResponse
    {
        return response()->json(['data' => $this->presenter->adjustment($s->revert($r->user(), AttendanceAdjustment::byUuid($uuid), (string) $r->input('reason')), $r->user())], 201);
    }

    // ---- delegations ---------------------------------------------------------------------------
    public function delegationIndex(Request $r): JsonResponse
    {
        $q = ApprovalDelegation::query()->orderByDesc('id');
        if (! $r->user()->allows('attendance.manage')) {
            $q->where(fn ($w) => $w->where('delegator_user_id', $r->user()->id)->orWhere('delegate_user_id', $r->user()->id));
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn ($d) => $this->presenter->delegation($d))->all()]);
    }

    public function delegationStore(Request $r): JsonResponse
    {
        $d = $this->validDelegation($r);
        $d['delegator_user_id'] = $r->user()->id;
        if ($r->filled('delegator_user') && $r->user()->allows('attendance.manage')) {
            $d['delegator_user_id'] = $this->userByEmployee($r, (string) $r->input('delegator_user'))->id;
        }
        abort_if($d['delegator_user_id'] === $d['delegate_user_id'], 422, 'You cannot delegate to the same person.');
        $x = DB::transaction(function () use ($d) {
            $x = ApprovalDelegation::create($d + ['uuid' => (string) Str::uuid(), 'active' => true]);
            Audit::record('created', 'attendance', 'delegation:'.$x->uuid, ['delegator' => $x->delegator_user_id, 'delegate' => $x->delegate_user_id, 'from' => $x->starts_on, 'to' => $x->ends_on, 'kinds' => $x->kinds]);

            return $x;
        });

        return response()->json(['data' => $this->presenter->delegation($x)], 201);
    }

    public function delegationUpdate(Request $r, string $uuid): JsonResponse
    {
        $x = $this->ownDelegation($r, $uuid);
        $d = $this->validDelegation($r);
        abort_if($x->delegator_user_id === $d['delegate_user_id'], 422, 'You cannot delegate to the same person.');
        $x->update($d + ['active' => $r->boolean('active', true)]);
        Audit::record('updated', 'attendance', 'delegation:'.$x->uuid, $d);

        return response()->json(['data' => $this->presenter->delegation($x->refresh())]);
    }

    public function delegationDestroy(Request $r, string $uuid): JsonResponse
    {
        $x = $this->ownDelegation($r, $uuid);
        $x->update(['active' => false]);
        Audit::record('revoked', 'attendance', 'delegation:'.$x->uuid);

        return response()->json(['message' => 'Delegation revoked.']);
    }

    private function ownDelegation(Request $r, string $uuid): ApprovalDelegation
    {
        $x = ApprovalDelegation::byUuid($uuid);
        abort_unless($x->delegator_user_id === $r->user()->id || $r->user()->allows('attendance.manage'), 404, 'That delegation was not found.');

        return $x;
    }

    private function validDelegation(Request $r): array
    {
        $in = $r->validate(['delegate_user' => 'required|string', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'kinds' => 'nullable|array', 'kinds.*' => 'in:regularization,overtime,on_duty,shift_swap,adjustment', 'reason' => 'nullable|string|max:250']);

        return ['delegate_user_id' => $this->userByEmployee($r, $in['delegate_user'])->id, 'starts_on' => $in['starts_on'], 'ends_on' => $in['ends_on'], 'kinds' => $in['kinds'] ?? null, 'reason' => $in['reason'] ?? null];
    }

    /** Delegates are named by employee UUID (never a raw user id); the employee must be in the caller's reach and have a login. */
    private function userByEmployee(Request $r, string $employeeUuid): User
    {
        $e = \App\Domain\People\Models\Employee::query()->where('uuid', $employeeUuid)->first();
        abort_unless($e, 404, 'That employee was not found.'); // delegates are any colleague with a login (directory-level reference)
        $uid = $this->approvers->userIdOf($e);
        $user = $uid ? User::query()->where('tenant_id', app(TenantContext::class)->id)->where('active', true)->find($uid) : null;
        if (! $user) {
            throw ValidationException::withMessages(['delegate_user' => 'That employee has no active login, so they cannot receive approvals.']);
        }

        return $user;
    }

    // ---- approvals inbox -----------------------------------------------------------------------
    public function approvals(Request $r): JsonResponse
    {
        $user = $r->user();
        $kind = (string) $r->input('kind', 'regularization');
        $status = (string) $r->input('status', 'pending');
        abort_unless(in_array($kind, ['regularization', 'overtime', 'on_duty', 'adjustment', 'shift_swap'], true), 422, 'Unknown request kind.');
        if ($kind === 'shift_swap') {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25, 'last_page' => 1], 'note' => 'Shift swaps are listed by the scheduling area.']);
        }
        $model = ['regularization' => Regularization::class, 'overtime' => OvertimeRequest::class, 'on_duty' => OnDutyRequest::class, 'adjustment' => AttendanceAdjustment::class][$kind];
        $dateCol = ['regularization' => 'attendance_date', 'overtime' => 'work_date', 'on_duty' => 'start_date', 'adjustment' => 'attendance_date'][$kind];
        $q = $model::query()->with('employee.person')->orderByDesc('id');
        $status !== 'all' && $q->where('status', $status === 'pending' && $kind === 'adjustment' ? 'pending_approval' : $status);
        // The approver view: only items the user can decide (or, with scope=team, all within data scope).
        $items = $q->limit(500)->get()->filter(function ($x) use ($user, $kind, $dateCol) {
            if ($kind === 'adjustment') {
                return $x->maker_id !== $user->id && $user->allows('attendance.adjust.approve');
            }
            $step = $kind === 'regularization' ? (int) $x->current_step : 1;
            $exclude = $kind === 'overtime' && $x->initiated_by === 'manager' ? [$x->requested_by] : [];

            return $this->approvers->canDecide($user, $x->employee, $kind, $x->{$dateCol}, $step, $exclude);
        })->values();
        $map = ['regularization' => fn ($x) => $this->presenter->regularization($x, $user), 'overtime' => fn ($x) => $this->presenter->overtime($x, $user), 'on_duty' => fn ($x) => $this->presenter->onDuty($x, $user), 'adjustment' => fn ($x) => $this->presenter->adjustment($x, $user)][$kind];

        return response()->json(['data' => $items->map($map)->all(), 'meta' => ['total' => $items->count()]]);
    }

    // ---- bulk ----------------------------------------------------------------------------------
    public function bulkActions(Request $r, BulkRunner $b): JsonResponse
    {
        return response()->json(['data' => $b->catalogue($r->user())]);
    }

    public function bulkPreview(Request $r, BulkRunner $b): JsonResponse
    {
        $in = $r->validate(['action' => 'required|string', 'params' => 'nullable|array', 'targets' => 'nullable|array', 'filters' => 'nullable|array']);

        return response()->json($b->preview($r->user(), $in['action'], $in['params'] ?? [], $in['targets'] ?? [], $in['filters'] ?? []));
    }

    public function bulkApply(Request $r, BulkRunner $b): JsonResponse
    {
        $run = $b->apply($r->user(), (string) $r->validate(['token' => 'required|string'])['token']);

        return response()->json($this->runShape($run), in_array($run->status, ['queued'], true) ? 202 : 200);
    }

    public function bulkShow(Request $r, string $uuid): JsonResponse
    {
        $run = AttendanceBulkRun::byUuid($uuid);
        abort_unless($run->user_id === $r->user()->id || $r->user()->allows('attendance.manage'), 404, 'That bulk run was not found.');

        return response()->json($this->runShape($run));
    }

    private function runShape(AttendanceBulkRun $run): array
    {
        return ['run' => $run->uuid, 'action' => $run->action, 'status' => $run->status, 'total' => $run->total, 'applied' => $run->applied, 'skipped' => $run->skipped, 'failed' => $run->failed, 'results' => $run->results];
    }

    // ---- shared list ---------------------------------------------------------------------------
    private function scoped(Request $r, $q, string $unused, string $column, callable $map): array
    {
        $user = $r->user();
        $own = $this->support->scope()->ownEmployeeIds($user);
        if ($r->input('scope') === 'team') {
            $this->support->scope()->constrain($q, $user, 'attendance.team.view', $column);
            $own && $q->whereNotIn($column, $own);
        } else {
            $q->whereIn($column, $own ?: [0]);
        }
        $r->filled('status') && $q->where('status', (string) $r->input('status'));

        return $this->paged($q->paginate($this->perPage($r)), $map);
    }
}
