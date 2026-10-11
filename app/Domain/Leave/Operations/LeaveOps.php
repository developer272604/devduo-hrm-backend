<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Attendance\Operations\Ops;
use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for leave read models (workstream D). Employee reach = tenant + DataScope per permission, exactly like
 * Attendance Ops. Other workstreams' services (policy resolver, entitlement engine) are resolved lazily and may be absent.
 */
final class LeaveOps
{
    /** Permissions that let a viewer see OTHER people's leave data (each limited by its own data scope). */
    public const VIEW = ['leave.team.view', 'leave.manage', 'leave.reports.view', 'leave.approve'];

    public static function tenantId(): int
    {
        return Ops::tenantId();
    }

    public static function today(): string
    {
        return Ops::today();
    }

    public static function iso(mixed $v): ?string
    {
        return Ops::iso($v);
    }

    public static function employees(User $user, array|string $permissions, bool $workingOnly = false): Builder
    {
        return Ops::employees($user, $permissions, $workingOnly);
    }

    public static function ownEmployee(User $user): ?object
    {
        return Ops::ownEmployee($user);
    }

    public static function perPage(mixed $v, int $default = 25): int
    {
        return Ops::perPage($v, $default);
    }

    public static function date(?string $d): ?string
    {
        return $d !== null && Ops::validDate($d) ? $d : null;
    }

    /** May the viewer see reasons/documents of sensitive (medical) leave? */
    public static function medical(User $user): bool
    {
        return $user->allows('leave.medical.view');
    }

    /** Lazy contract lookups: null when the implementing workstream has not bound the contract yet. */
    public static function resolver(): ?LeavePolicyResolver
    {
        return self::lazy(LeavePolicyResolver::class);
    }

    public static function engine(): ?EntitlementEngine
    {
        return self::lazy(EntitlementEngine::class);
    }

    public static function lazy(string $abstract): mixed
    {
        if (! app()->bound($abstract)) {
            return null;
        }
        try {
            return app($abstract);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function employeeByUuid(string $uuid): ?object
    {
        return DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', self::tenantId())->where('e.uuid', $uuid)
            ->whereNull('e.deleted_at')->first(['e.id', 'e.uuid', 'e.employee_number', 'e.status', 'e.joined_on', 'e.separated_on', 'p.display_name']);
    }

    /** Resolves an employee the viewer may see for $permissions (or themself when allowed); 404 otherwise (never reveals existence). */
    public static function visibleEmployee(User $user, string $uuid, array|string $permissions, ?string $selfPermission = 'leave.view'): object
    {
        $own = self::ownEmployee($user);
        if ($own && $own->uuid === $uuid && $selfPermission && $user->allows($selfPermission)) {
            return self::employeeByUuid($uuid) ?? abort(404);
        }
        $row = self::employees($user, $permissions)->where('e.uuid', $uuid)->first(['e.id', 'e.uuid', 'e.employee_number', 'e.status', 'e.joined_on', 'e.separated_on', 'p.display_name']);
        abort_unless($row, 404);

        return $row;
    }
}
