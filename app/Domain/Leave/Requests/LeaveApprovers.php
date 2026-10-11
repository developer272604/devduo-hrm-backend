<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\Leave\Contracts\LeaveApproverResolver;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Leave approver routing. There is NO leave approval engine: this class only translates the policy's approval steps
 * (approvals.steps = ['manager', 'hr', ...]) into calls on the Attendance ApproverResolver (kind `leave` => `leave.approve`),
 * which already handles the manager-as-of-date, active delegations, self-approval exclusion and HR fallback.
 *
 * The roles of a request are fixed at submission (policy steps, plus an HR step appended by conditional escalation) and
 * stored in leave_requests.explanation.approval.roles, so a later policy edit never re-routes a request in flight.
 */
final class LeaveApprovers implements LeaveApproverResolver
{
    public const KIND = 'leave';

    public function __construct(private ApproverResolver $approvers, private DataScope $scope) {}

    /** Policy steps for the employee on the date (manager | hr). */
    public function roles(Employee $employee, string $date): array
    {
        $steps = null;
        if (app()->bound(LeavePolicyResolver::class)) {
            $steps = data_get(app(LeavePolicyResolver::class)->resolve($employee->id, $date), 'version.rules.approvals.steps');
        }
        $steps = is_array($steps) && $steps ? $steps : (array) LeaveSettings::get('approvals.steps', ['manager']);
        $steps = array_values(array_map(fn ($s) => $s === 'hr' ? 'hr' : 'manager', $steps));

        return $steps ?: ['manager'];
    }

    public function totalSteps(Employee $employee, string $date): int
    {
        return count($this->roles($employee, $date));
    }

    public function forEmployee(Employee $employee, string $date, int $step = 1, array $exclude = []): ?User
    {
        return $this->candidates($employee, $date, $step, $exclude)->first();
    }

    /** @return Collection<int,User> */
    public function candidates(Employee $employee, string $date, int $step = 1, array $exclude = [], ?array $roles = null): Collection
    {
        $roles ??= $this->roles($employee, $date);

        return $this->forRole($employee, $date, $roles[max(0, $step - 1)] ?? 'manager', $exclude);
    }

    /** @return Collection<int,User> */
    public function forRole(Employee $employee, string $date, string $role, array $exclude = []): Collection
    {
        if ($role === 'manager') {
            return $this->approvers->candidates($employee, self::KIND, $date, 1, $exclude);
        }
        $self = $this->approvers->userIdOf($employee);
        $ids = array_values(array_diff($this->approvers->hrUsers(self::KIND), array_filter([...$exclude, $self])));

        return $ids ? User::query()->whereIn('id', $ids)->where('active', true)->orderBy('id')->get() : collect();
    }

    /** Roles stored on a request (fallback: derive from the policy). */
    public function rolesOf(LeaveRequest $request, Employee $employee): array
    {
        $stored = $request->explanation['approval']['roles'] ?? null;

        return is_array($stored) && $stored ? $stored : $this->roles($employee, $request->start_date);
    }

    public function candidatesFor(LeaveRequest $request, Employee $employee, ?int $step = null): Collection
    {
        $self = $this->approvers->userIdOf($employee);
        // Two-person control: someone who raised the request on the employee's behalf never decides it.
        $exclude = $request->requested_by && $request->requested_by !== $self ? [(int) $request->requested_by] : [];
        $step ??= (int) $request->current_step;
        $found = $this->candidates($employee, $request->start_date, $step, $exclude, $this->rolesOf($request, $employee));
        if (! empty($request->explanation['approval']['escalated']) && $step === (int) $request->current_step) {
            $found = $found->concat($this->forRole($employee, $request->start_date, 'hr', $exclude))->unique('id')->values();
        }

        return $found;
    }

    public function canDecide(User $actor, LeaveRequest $request, Employee $employee): bool
    {
        if (! $actor->active || $this->approvers->userIdOf($employee) === $actor->id) {
            return false;
        }
        if ($this->candidatesFor($request, $employee)->contains('id', $actor->id)) {
            return true;
        }

        // HR override so a request is never stranded behind an absent manager: explicit permission + tenant-wide scope.
        return $actor->allows('leave.manage') && $this->scope->hasTenantWide($actor, 'leave.manage');
    }

    /** When the actor decides as a delegate of the manager, the delegator's user id (stored as on_behalf_of in the action row). */
    public function onBehalfOf(User $actor, LeaveRequest $request, Employee $employee): ?int
    {
        $role = $this->rolesOf($request, $employee)[max(0, (int) $request->current_step - 1)] ?? 'manager';

        return $role === 'manager' ? $this->approvers->onBehalfOf($actor, $employee, self::KIND, $request->start_date, 1) : null;
    }

    public function userIdOf(Employee $employee): ?int
    {
        return $this->approvers->userIdOf($employee);
    }

    public function hrUsers(): array
    {
        return $this->approvers->hrUsers(self::KIND);
    }
}
