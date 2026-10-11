<?php

namespace App\Domain\Access;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Server-side organizational data-scope engine.
 *
 * A user's reach is the UNION of (a) the scope of every role that grants the permission being checked,
 * (b) explicit per-user grants (user_data_scopes) and (c) always the user's own employments. Scope is
 * evaluated against the *current* effective-dated assignment and reporting relationships.
 *
 * Scopes: self | direct_reports | all_subordinates | department | business_unit | location |
 *         legal_entity | custom_group | explicit | tenant   (legacy 'team' = direct_reports)
 */
final class DataScope
{
    public const SCOPES = ['self', 'direct_reports', 'all_subordinates', 'department', 'business_unit', 'location', 'legal_entity', 'custom_group', 'explicit', 'tenant'];

    private const ORGANIZATION = ['department' => 'department_id', 'business_unit' => 'business_unit_id', 'location' => 'location_id', 'legal_entity' => 'legal_entity_id'];

    public static function normalize(string $scope): string
    {
        return $scope === 'team' ? 'direct_reports' : $scope;
    }

    /** @return array<int,string> distinct scopes the user holds for the permission (null permission = any role). */
    public function scopesFor(User $user, ?string $permission = null): array
    {
        return $user->roles()->with('permissions')->get()
            ->filter(fn ($role) => ! $permission || $role->permissions->contains('key', $permission))
            ->map(fn ($role) => self::normalize($role->data_scope))->unique()->values()->all();
    }

    public function hasTenantWide(User $user, ?string $permission = null): bool
    {
        return in_array('tenant', $this->scopesFor($user, $permission), true);
    }

    /** Employee ids of the user's own employments (all statuses; a separated person still sees own history). */
    public function ownEmployeeIds(User $user): array
    {
        return DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')
            ->where('employees.tenant_id', $user->tenant_id)->where('people.user_id', $user->id)->whereNull('employees.deleted_at')->pluck('employees.id')->all();
    }

    /**
     * Restrict an Eloquent/query builder over the employees table to the user's reach.
     * $employeeColumn is the qualified column holding the employee id (default employees.id).
     */
    public function constrain(EloquentBuilder|Builder $query, User $user, ?string $permission = 'people.view', string $employeeColumn = 'employees.id'): EloquentBuilder|Builder
    {
        $scopes = $this->scopesFor($user, $permission);
        if (in_array('tenant', $scopes, true)) {
            return $query;
        }
        [$sql, $bindings] = $this->reachSql($user, $scopes);
        $sql === null ? $query->whereRaw('1 = 0') : $query->whereRaw("$employeeColumn IN ($sql)", $bindings);

        return $query;
    }

    public function canAccess(User $user, Employee|int $employee, ?string $permission = 'people.view'): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $this->constrain(DB::table('employees')->where('employees.tenant_id', $user->tenant_id)->where('employees.id', $id), $user, $permission)->exists();
    }

    /** True when the viewer reaches the subject through a managerial relationship (not merely HR/tenant scope or self). */
    public function isManagerOf(User $user, Employee|int $employee): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;
        $own = $this->ownEmployeeIds($user);
        if (! $own || in_array($id, $own, true)) {
            return false;
        }
        [$cte, $select, $bindings] = $this->subordinatesSql($own, true);

        return DB::table('employees')->where('employees.id', $id)->whereRaw("employees.id IN ($cte $select)", $bindings)->exists();
    }

    public function isSelf(User $user, Employee|int $employee): bool
    {
        return in_array($employee instanceof Employee ? $employee->id : $employee, $this->ownEmployeeIds($user), true);
    }

    /** @return array{0:?string,1:array} a SQL subquery returning employee ids, or null when nothing is reachable. */
    private function reachSql(User $user, array $scopes): array
    {
        $parts = [];
        $bindings = [];
        $cte = '';
        $cteBindings = [];
        $today = TenantClock::today();
        $own = $this->ownEmployeeIds($user);
        $person = DB::table('people')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id');

        if ($person) {
            $parts[] = 'SELECT e.id FROM employees e WHERE e.person_id = ? AND e.deleted_at IS NULL';
            $bindings[] = $person;
        }
        $grants = DB::table('user_data_scopes')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->get()->groupBy('kind');

        foreach ($scopes as $scope) {
            if ($scope === 'direct_reports' && $own) {
                [, $select, $b] = $this->subordinatesSql($own, false);
                $parts[] = $select;
                array_push($bindings, ...$b);
            }
            if ($scope === 'all_subordinates' && $own && $cte === '') {
                [$cte, $select, $cteBindings] = $this->subordinatesSql($own, true);
                $parts[] = $select;
            }
        }
        // Organizational scopes: the user's own current dimension values plus explicit grants.
        foreach (self::ORGANIZATION as $scope => $column) {
            $values = collect($grants->get($scope, []))->pluck('reference_id');
            if (in_array($scope, $scopes, true) && $own) {
                $values = $values->merge(DB::table('employee_assignments')->whereIn('employee_id', $own)->where('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->pluck($column)->filter());
            }
            $values = $values->unique()->values();
            if ($values->isNotEmpty()) {
                $in = implode(',', array_fill(0, $values->count(), '?'));
                $parts[] = "SELECT a.employee_id FROM employee_assignments a WHERE a.$column IN ($in) AND a.effective_from <= ? AND (a.effective_to IS NULL OR a.effective_to >= ?)";
                array_push($bindings, ...[...$values->all(), $today, $today]);
            }
        }
        $groups = collect($grants->get('employee_group', []))->pluck('reference_id');
        if ($groups->isNotEmpty() || in_array('custom_group', $scopes, true)) {
            if ($groups->isNotEmpty()) {
                $in = implode(',', array_fill(0, $groups->count(), '?'));
                $parts[] = "SELECT m.employee_id FROM employee_group_members m WHERE m.group_id IN ($in) AND (m.effective_from IS NULL OR m.effective_from <= ?) AND (m.effective_to IS NULL OR m.effective_to >= ?)";
                array_push($bindings, ...[...$groups->all(), $today, $today]);
            }
        }
        $explicit = collect($grants->get('employee', []))->pluck('reference_id');
        if ($explicit->isNotEmpty()) {
            $parts[] = 'SELECT x.id FROM employees x WHERE x.id IN ('.implode(',', array_fill(0, $explicit->count(), '?')).')';
            array_push($bindings, ...$explicit->all());
        }

        // A CTE must lead the whole statement, so it is hoisted in front of the UNION of reach arms.
        return $parts ? [trim($cte.' '.implode(' UNION ', $parts)), [...$cteBindings, ...$bindings]] : [null, []];
    }

    /**
     * Subquery of employees reporting (primary line) to the given managers. $recursive = whole subtree.
     * The recursive CTE uses UNION (set semantics), so a corrupt cycle terminates instead of looping.
     *
     * @return array{0:string,1:string,2:array} [cte (may be ''), select, bindings]
     */
    private function subordinatesSql(array $managerIds, bool $recursive): array
    {
        $today = TenantClock::today();
        $in = implode(',', array_fill(0, count($managerIds), '?'));
        $current = 'r.type = \'primary\' AND r.effective_from <= ? AND (r.effective_to IS NULL OR r.effective_to >= ?)';
        if (! $recursive) {
            return ['', "SELECT r.employee_id FROM employee_reporting_relationships r WHERE r.manager_id IN ($in) AND $current", [...$managerIds, $today, $today]];
        }

        return ["WITH RECURSIVE subtree(employee_id) AS (SELECT r.employee_id FROM employee_reporting_relationships r WHERE r.manager_id IN ($in) AND $current UNION SELECT r.employee_id FROM employee_reporting_relationships r JOIN subtree s ON r.manager_id = s.employee_id WHERE $current)",
            'SELECT employee_id FROM subtree', [...$managerIds, $today, $today, $today, $today]];
    }

    /** Convenience for tests and background jobs. */
    public function tenantId(): ?int
    {
        return app(TenantContext::class)->id;
    }
}
