<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\ApprovalDelegation;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\ReportingService;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE approver routing service for attendance requests (regularization, overtime, on-duty, shift swaps).
 *
 * A step is routed by ROLE, never by a stored user id:
 *   manager  the employee's primary manager AS OF the request date (ReportingService), then that manager's active delegates
 *   hr       every active user holding the kind's approve permission with tenant-wide scope
 * The requester's own user is always excluded (self-approval is impossible), as is any id in $exclude (e.g. the manager who
 * initiated an overtime request). When a manager step has nobody able to decide (no manager, manager has no login, manager is the
 * requester) it falls back to HR so a request is never stranded.
 */
final class ApproverResolver
{
    public const KINDS = ['regularization', 'overtime', 'on_duty', 'shift_swap', 'adjustment', 'leave'];

    private const PERMISSION = ['overtime' => 'attendance.overtime.approve', 'adjustment' => 'attendance.adjust.approve', 'leave' => 'leave.approve'];

    public function __construct(private ReportingService $reporting, private DataScope $scope) {}

    public static function permissionFor(string $kind): string
    {
        return self::PERMISSION[$kind] ?? 'attendance.approve';
    }

    /** Ordered roles of the workflow for this kind, e.g. ['manager','hr']. */
    public function roles(Employee $employee, string $kind, string $date): array
    {
        return $kind === 'regularization' ? RequestRules::regularization($employee, $date)['approval_steps'] : ['manager'];
    }

    public function totalSteps(Employee $employee, string $kind, string $date): int
    {
        return count($this->roles($employee, $kind, $date));
    }

    /** The user the request is routed to for this step (a delegate first while a delegation is active), or null when nobody can approve. */
    public function forEmployee(Employee $employee, string $kind, string $date, int $step = 1, array $exclude = []): ?User
    {
        return $this->candidates($employee, $kind, $date, $step, $exclude)->first();
    }

    /** Everyone who may decide this step, delegates before the delegator. @return Collection<int,User> */
    public function candidates(Employee $employee, string $kind, string $date, int $step = 1, array $exclude = []): Collection
    {
        $exclude = array_values(array_filter([...$exclude, $this->userIdOf($employee)]));
        $role = $this->roles($employee, $kind, $date)[max(0, $step - 1)] ?? 'manager';
        $ids = [];
        if ($role === 'manager') {
            $manager = $this->reporting->getManager($employee, 'primary', $date);
            $managerUser = $manager ? $this->userIdOf($manager) : null;
            if ($managerUser && ! in_array($managerUser, $exclude, true) && $this->activeUser($managerUser)) {
                foreach ($this->delegatesOf($managerUser, $kind) as $delegate) {
                    $ids[] = $delegate;
                }
                $ids[] = $managerUser;
            }
        }
        if ($role === 'hr' || ! $ids) {
            array_push($ids, ...$this->hrUsers($kind));
        }
        $ids = array_values(array_diff(array_unique($ids), $exclude));

        return $ids ? User::query()->whereIn('id', $ids)->where('active', true)->get()->sortBy(fn ($u) => array_search($u->id, $ids, true))->values() : collect();
    }

    public function canDecide(User $actor, Employee $employee, string $kind, string $date, int $step = 1, array $exclude = []): bool
    {
        if (! $actor->active || $actor->id === $this->userIdOf($employee) || in_array($actor->id, $exclude, true)) {
            return false;
        }
        if ($this->candidates($employee, $kind, $date, $step, $exclude)->contains('id', $actor->id)) {
            return true;
        }

        // HR/admin override so a request is never stranded behind an absent manager (explicit permission + tenant-wide scope).
        return $actor->allows('attendance.manage') && $this->scope->hasTenantWide($actor, 'attendance.manage');
    }

    /** When the actor decides as a delegate, the delegator's user id (stored as on_behalf_of); otherwise null. */
    public function onBehalfOf(User $actor, Employee $employee, string $kind, string $date, int $step = 1): ?int
    {
        $role = $this->roles($employee, $kind, $date)[max(0, $step - 1)] ?? 'manager';
        if ($role !== 'manager') {
            return null;
        }
        $manager = $this->reporting->getManager($employee, 'primary', $date);
        $managerUser = $manager ? $this->userIdOf($manager) : null;
        if ($managerUser && $managerUser !== $actor->id && in_array($actor->id, $this->delegatesOf($managerUser, $kind), true)) {
            return $managerUser;
        }

        return null;
    }

    /** User ids that currently act for $delegatorUserId on this kind. */
    public function delegatesOf(int $delegatorUserId, string $kind): array
    {
        $today = TenantClock::today();

        return ApprovalDelegation::query()->where('delegator_user_id', $delegatorUserId)->where('active', true)
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->get()
            ->filter(fn ($d) => empty($d->kinds) || in_array($kind, $d->kinds, true))
            ->pluck('delegate_user_id')->filter(fn ($id) => $this->activeUser((int) $id))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Delegations that currently make $user a delegate (any kind). @return array<int,int> delegator user ids */
    public function delegatorsOf(User $user): array
    {
        $today = TenantClock::today();

        return ApprovalDelegation::query()->where('delegate_user_id', $user->id)->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->pluck('delegator_user_id')->all();
    }

    /** @return array<int,int> active users with the kind's approve permission and tenant-wide scope */
    public function hrUsers(string $kind): array
    {
        return DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('users.active', true)
            ->where('roles.data_scope', 'tenant')->where('permissions.key', self::permissionFor($kind))
            ->orderBy('users.id')->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function userIdOf(Employee $employee): ?int
    {
        $id = DB::table('people')->where('id', $employee->person_id)->value('user_id');

        return $id ? (int) $id : null;
    }

    private function activeUser(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('active', true)->exists();
    }
}
