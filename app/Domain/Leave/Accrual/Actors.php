<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Small lookups shared by the ledger-operation services (Employee is not User: the login is found through the person). */
final class Actors
{
    public static function userIdOf(int $employeeId): ?int
    {
        $id = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $employeeId)
            ->where('employees.tenant_id', app(TenantContext::class)->id)->value('people.user_id');

        return $id ? (int) $id : null;
    }

    /** Active users of this workspace holding a permission through any role. @return array<int,int> */
    public static function usersWithPermission(string $permission, array $exclude = []): array
    {
        return DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', app(TenantContext::class)->id)->where('users.active', true)->where('permissions.key', $permission)
            ->whereNotIn('users.id', $exclude)->distinct()->pluck('users.id')->map(fn ($v) => (int) $v)->all();
    }
}
