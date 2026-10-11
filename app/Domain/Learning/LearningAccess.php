<?php

namespace App\Domain\Learning;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\{TenantClock, TenantContext};
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Authorization helpers for learning data: permission + DataScope + self/manager relationship. Cross-tenant ids resolve to 404 through TenantRecord. */
final class LearningAccess
{
    public function __construct(private DataScope $scope) {}

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

    public function reaches(User $u, Employee|int $e, string $permission): bool
    {
        return $u->allows($permission) && $this->scope->canAccess($u, $e, $permission);
    }

    /** Own employment, or in scope for the permission. */
    public function selfOrReaches(User $u, Employee|int $e, string $permission): bool
    {
        return ($this->isSelf($u, $e) && $u->allows('learning.self')) || $this->reaches($u, $e, $permission);
    }

    public function reachIds(User $u, string $permission)
    {
        return $this->scope->constrain(DB::table('employees')->where('employees.tenant_id', $u->tenant_id)->whereNull('employees.deleted_at'), $u, $permission)->select('employees.id');
    }

    public function tenantWide(User $u, string $permission): bool
    {
        return $u->allows($permission) && $this->scope->hasTenantWide($u, $permission);
    }

    public function userIdOf(Employee|int $e): ?int
    {
        $personId = $e instanceof Employee ? $e->person_id : Employee::whereKey($e)->value('person_id');
        $id = $personId ? DB::table('people')->where('id', $personId)->value('user_id') : null;

        return $id ? (int) $id : null;
    }

    public function assignmentRow(int $employeeId, ?string $date = null): ?object
    {
        $date ??= TenantClock::today();

        return DB::table('employee_assignments')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
    }

    public function managerUserId(Employee|int $e): ?int
    {
        $id = $e instanceof Employee ? $e->id : $e; $date = TenantClock::today();
        $row = DB::table('employee_reporting_relationships')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $id)->where('type', 'primary')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();

        return $row ? $this->userIdOf((int) $row->manager_id) : null;
    }

    /** Users who may decide an item for this employee: the primary manager (and delegates), falling back to tenant-wide HR; always filtered by the required permission and never the subject. @return array<int,int> */
    public function approvers(Employee $e, string $permission): array
    {
        $resolver = app(ApproverResolver::class);
        $users = $resolver->candidates($e, 'learning', TenantClock::today())->filter(fn (User $u) => $u->allows($permission));
        $ids = $users->pluck('id')->all();
        if (! $ids) $ids = User::whereIn('id', $resolver->hrUsers('learning'))->get()->filter(fn (User $u) => $u->allows($permission))->pluck('id')->all();
        $self = $this->userIdOf($e);

        return array_values(array_filter(array_slice($ids, 0, 5), fn ($id) => $id !== $self));
    }

    public function name(Employee|int|null $e): ?string
    {
        if (! $e) return null;
        $e = $e instanceof Employee ? $e : Employee::with('person')->find($e);
        $e?->loadMissing('person');

        return $e ? trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? '')) : null;
    }

    public function activeEmployeeIds(): array
    {
        return DB::table('employees')->where('tenant_id', app(TenantContext::class)->id)->whereNull('deleted_at')->whereNotIn('status', ['separated', 'inactive', 'archived', 'preboarding'])->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
