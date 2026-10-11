<?php

namespace App\Domain\Performance;

use App\Domain\Access\DataScope;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\Goal;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Authorization helpers for performance data: permission + DataScope + relationship (self / manager). Cross-tenant ids resolve to 404 through TenantRecord. */
final class PerformanceAccess
{
    public function __construct(private DataScope $scope) {}

    public function scope(): DataScope
    {
        return $this->scope;
    }

    public function employeeByUuid(string $uuid): Employee
    {
        return Employee::where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<int,int> */
    public function own(User $u): array
    {
        return $this->scope->ownEmployeeIds($u);
    }

    public function isSelf(User $u, Employee|int $e): bool
    {
        return $this->scope->isSelf($u, $e);
    }

    /** Own employee, or a person in the user's data scope for the permission. Returns false when the permission is not held. */
    public function reaches(User $u, Employee|int $e, string $permission): bool
    {
        return $u->allows($permission) && $this->scope->canAccess($u, $e, $permission);
    }

    /** Subquery of employee ids the user reaches for the permission (own employments always included by DataScope). */
    public function reachIds(User $u, string $permission)
    {
        return $this->scope->constrain(DB::table('employees')->where('employees.tenant_id', $u->tenant_id)->whereNull('employees.deleted_at'), $u, $permission)->select('employees.id');
    }

    public function tenantWide(User $u, string $permission): bool
    {
        return $u->allows($permission) && $this->scope->hasTenantWide($u, $permission);
    }

    public function userIdOf(Employee $e): ?int
    {
        $id = DB::table('people')->where('id', $e->person_id)->value('user_id');

        return $id ? (int) $id : null;
    }

    /** The viewer's primary manager chain relation to the subject (direct or indirect). */
    public function isManagerOf(User $u, Employee|int $e): bool
    {
        return $this->scope->isManagerOf($u, $e);
    }

    public function departmentOf(Employee|int $e, ?string $date = null): ?int
    {
        $id = $e instanceof Employee ? $e->id : $e;

        return $this->assignmentRow($id, $date)?->department_id;
    }

    public function assignmentRow(int $employeeId, ?string $date = null): ?object
    {
        $date ??= TenantClock::today();

        return DB::table('employee_assignments')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
    }

    /** Primary manager (employee id) as of a date. */
    public function managerOf(Employee $e, ?string $date = null): ?Employee
    {
        $date ??= TenantClock::today();
        $row = DB::table('employee_reporting_relationships')->where('tenant_id', $e->tenant_id)->where('employee_id', $e->id)->where('type', 'primary')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();

        return $row ? Employee::find($row->manager_id) : null;
    }

    /** Goals the user may read: own / created, company-visible, in data scope (performance.view), or same department for team visibility. */
    public function visibleGoals(User $u)
    {
        $own = $this->own($u);
        $depts = $own ? DB::table('employee_assignments')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $own)->where('effective_from', '<=', TenantClock::today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', TenantClock::today()))->whereNotNull('department_id')->pluck('department_id')->all() : [];

        return Goal::query()->where(function ($q) use ($u, $own, $depts) {
            $q->where('created_by', $u->id)->orWhere('visibility', 'company');
            if ($own) $q->orWhereIn('owner_employee_id', $own);
            if ($depts) $q->orWhere(fn ($w) => $w->where('visibility', 'team')->whereIn('department_id', $depts));
            if ($u->allows('performance.view')) {
                if ($this->scope->hasTenantWide($u, 'performance.view')) $q->orWhereRaw('1 = 1');
                else $q->orWhereIn('owner_employee_id', $this->reachIds($u, 'performance.view'));
            }
        });
    }
}
