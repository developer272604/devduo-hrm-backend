<?php

namespace App\Http\Controllers\Leave\Requests;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Requests\LeaveApprovers;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestPresenter;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/** Base for leave request controllers: UUID addressing, 404 (never 403) for anything the viewer cannot see. */
abstract class LeaveController extends Controller
{
    public function __construct(protected LeaveSupport $support, protected LeaveApprovers $approvers, protected RequestPresenter $presenter) {}

    protected function request(string $uuid): LeaveRequest
    {
        return LeaveRequest::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** Visible to: the employee, data scope + leave.team.view, an approver, or HR (leave.manage in scope). */
    protected function visible(Request $r, LeaveRequest $request): Employee
    {
        $user = $r->user();
        $employee = Employee::query()->with('person')->findOrFail($request->employee_id);
        $scope = $this->support->scope();
        $ok = $scope->isSelf($user, $employee) || ($user->allows('leave.team.view') && $scope->canAccess($user, $employee, 'leave.team.view')) || ($user->allows('leave.manage') && $scope->canAccess($user, $employee, 'leave.manage'))
            || ($user->allows('leave.approve') && $this->approvers->canDecide($user, $request, $employee));
        abort_unless($ok, 404, 'That leave request was not found.');

        return $employee;
    }

    protected function perPage(Request $r, int $default = 25): int
    {
        return min(100, max(5, (int) $r->input('per_page', $default)));
    }

    protected function paged(LengthAwarePaginator $page, callable $map): array
    {
        return ['data' => collect($page->items())->map($map)->values()->all(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]];
    }

    protected function idempotencyKey(Request $r): ?string
    {
        $key = $r->header('Idempotency-Key', $r->input('idempotency_key'));

        return is_string($key) && trim($key) !== '' ? mb_substr(trim($key), 0, 100) : null;
    }

    /** Own employment unless an employee UUID is given (then leave.manage within scope). */
    protected function subject(Request $r): Employee
    {
        $uuid = $r->input('employee');

        return $uuid ? $this->support->employee($r->user(), (string) $uuid, 'leave.manage') : $this->support->ownEmployee($r->user());
    }

    /** @return array<int,\Illuminate\Http\UploadedFile> */
    protected function files(Request $r): array
    {
        $files = $r->file('documents') ?? [];

        return is_array($files) ? array_values($files) : [$files];
    }
}
