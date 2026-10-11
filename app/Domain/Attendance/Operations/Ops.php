<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Small shared helpers for the time-office read models: tenant id, tenant "today", scoped employee query, formatting. */
final class Ops
{
    /** Employments that are expected to work (not preboarding, separated, suspended or archived). */
    public const WORKING = ['active', 'probation', 'notice_period'];

    public static function tenantId(): int
    {
        $id = app(TenantContext::class)->id;
        abort_unless($id, 422, 'Tenant context is required.');

        return (int) $id;
    }

    public static function today(): string
    {
        return TenantClock::today();
    }

    /**
     * employees e + people p, tenant restricted, limited to what the user may reach through ANY of the permissions they hold
     * (DataScope is evaluated per permission: the roles that grant it). A user holding none of them reaches nothing.
     *
     * @param  array<int,string>|string  $permissions
     */
    public static function employees(User $user, array|string $permissions, bool $workingOnly = false): Builder
    {
        $q = DB::table('employees as e')->join('people as p', function ($j) {
            $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at');
        })->where('e.tenant_id', self::tenantId())->whereNull('e.deleted_at');
        if ($workingOnly) {
            $q->whereIn('e.status', self::WORKING);
        }
        $held = array_values(array_filter((array) $permissions, fn ($p) => $user->allows($p)));
        $scope = app(DataScope::class);
        if (! $held) {
            return $q->whereRaw('1 = 0');
        }
        foreach ($held as $permission) {
            if ($scope->hasTenantWide($user, $permission)) {
                return $q;
            }
        }
        if (count($held) === 1) {
            return $scope->constrain($q, $user, $held[0], 'e.id');
        }

        return $q->where(function ($w) use ($scope, $user, $held) {
            foreach ($held as $permission) {
                $w->orWhere(fn ($x) => $scope->constrain($x, $user, $permission, 'e.id'));
            }
        });
    }

    /** @return array<int,int> */
    public static function employeeIds(User $user, array|string $permissions, bool $workingOnly = true): array
    {
        return self::employees($user, $permissions, $workingOnly)->pluck('e.id')->map(fn ($v) => (int) $v)->all();
    }

    /** The user's own current employment id (the most recent non-separated one), or null. */
    public static function ownEmployee(User $user): ?object
    {
        return DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', self::tenantId())->where('p.user_id', $user->id)
            ->whereNull('e.deleted_at')->select('e.id', 'e.uuid', 'e.employee_number', 'e.timezone', 'e.status', 'p.display_name')
            ->orderByRaw("CASE WHEN e.status IN ('active','probation','notice_period') THEN 0 ELSE 1 END")->orderByDesc('e.id')->first();
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function employeeRef(object $row): array
    {
        return ['employee_uuid' => $row->uuid ?? $row->employee_uuid ?? null, 'employee_number' => $row->employee_number ?? null, 'name' => $row->display_name ?? $row->name ?? null];
    }

    /** Friendly display label of an internal status, honouring the tenant's label overrides. */
    public static function statusLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return \App\Domain\Attendance\Support\AttendanceStatus::label($status, \App\Domain\Attendance\Support\AttendanceSettings::statusLabels());
    }

    /** Clamps a requested page size to 1..100. */
    public static function perPage(mixed $value, int $default = 25): int
    {
        return min(100, max(1, (int) ($value ?: $default)));
    }

    /** Whether the date string is a valid Y-m-d. */
    public static function validDate(?string $date): bool
    {
        return $date !== null && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && CarbonImmutable::hasFormat($date, 'Y-m-d');
    }
}
