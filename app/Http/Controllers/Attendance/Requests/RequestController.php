<?php

namespace App\Http\Controllers\Attendance\Requests;

use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\Attendance\Requests\RequestPresenter;
use App\Domain\Attendance\Requests\RequestSupport;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Base for attendance request controllers. Everything is addressed by public UUID and tenant-scoped by the models;
 * a request the viewer may neither see nor decide is answered 404 (never 403) so its existence is not disclosed.
 */
abstract class RequestController extends Controller
{
    public function __construct(protected RequestSupport $support, protected ApproverResolver $approvers, protected RequestPresenter $presenter) {}

    /** Visible to: the employee, anyone whose data scope reaches the employee with attendance.team.view, or an approver of the request. */
    protected function mayView(Request $r, Employee $employee, string $kind, string $date, int $step = 1, array $exclude = []): bool
    {
        $user = $r->user();

        return $this->support->isSelf($user, $employee) || $this->support->scope()->canAccess($user, $employee, 'attendance.team.view') || $this->approvers->canDecide($user, $employee, $kind, $date, $step, $exclude);
    }

    protected function perPage(Request $r, int $default = 25): int
    {
        return min(100, max(5, (int) $r->input('per_page', $default)));
    }

    protected function paged(LengthAwarePaginator $page, callable $map): array
    {
        return ['data' => collect($page->items())->map($map)->values()->all(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]];
    }

    /** The key a client sends to make a submit safe to retry. */
    protected function idempotencyKey(Request $r): ?string
    {
        $key = $r->header('Idempotency-Key', $r->input('idempotency_key'));

        return is_string($key) && trim($key) !== '' ? mb_substr(trim($key), 0, 100) : null;
    }

    /** Resolves the subject of a submit: the caller's own employment unless an employee UUID is given (then it must be in scope with $permission). */
    protected function subject(Request $r, string $permission): Employee
    {
        $uuid = $r->input('employee');
        if (! $uuid) {
            return $this->support->ownEmployee($r->user());
        }

        return $this->support->employee($r->user(), (string) $uuid, $permission);
    }
}
