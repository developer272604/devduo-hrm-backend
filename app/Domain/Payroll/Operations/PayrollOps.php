<?php

namespace App\Domain\Payroll\Operations;

use App\Domain\Attendance\Operations\Ops;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/** Small shared helpers for payroll operations read models (workstream D). Employee reach = tenant + DataScope per permission. */
final class PayrollOps
{
    /** Permissions that let a viewer see operational payroll state of OTHER people (each limited by its own data scope). */
    public const VIEW = ['payroll.view', 'payroll.run', 'payroll.configure', 'payroll.results.view', 'payroll.compensation.view'];

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

    /** @param array<int,string>|string $permissions */
    public static function employees(User $user, array|string $permissions, bool $workingOnly = false): Builder
    {
        return Ops::employees($user, $permissions, $workingOnly);
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

    public static function moduleEnabled(User $user, string $module): bool
    {
        return in_array($module, $user->tenant->settings['modules'] ?? [], true);
    }

    public static function any(User $user, array $permissions): bool
    {
        return (bool) array_filter($permissions, fn ($p) => $user->allows($p));
    }

    /** Masks a monetary value unless $visible. */
    public static function amount(?string $value, bool $visible): ?string
    {
        return $visible ? $value : null;
    }
}
