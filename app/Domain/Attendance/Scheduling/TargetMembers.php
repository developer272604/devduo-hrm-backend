<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Which employees does an assignment target reach inside a date range? (used by locks, impact preview and write-time checks) */
final class TargetMembers
{
    public const ORGANIZATION = ['team' => ['teams', 'team_id'], 'department' => ['departments', 'department_id'], 'business_unit' => ['business_units', 'business_unit_id'],
        'location' => ['locations', 'location_id'], 'employee_type' => ['employee_types', 'employee_type_id'], 'legal_entity' => ['legal_entities', 'legal_entity_id']];

    /** @return array<int,int> employee ids employed at some point in the range whose organization matches the target at some point in it */
    public static function employeeIds(string $type, string $id, string $from, string $to): array
    {
        $tenant = app(TenantContext::class)->id;
        $employed = DB::table('employees')->where('employees.tenant_id', $tenant)->whereNull('employees.deleted_at')->where('employees.status', '!=', 'preboarding')
            ->where('employees.joined_on', '<=', $to)->where(fn ($q) => $q->whereNull('employees.separated_on')->orWhere('employees.separated_on', '>=', $from));

        if ($type === 'tenant') {
            return $employed->pluck('employees.id')->map(fn ($v) => (int) $v)->all();
        }
        if ($type === 'employee') {
            return $employed->where('employees.id', $id)->pluck('employees.id')->map(fn ($v) => (int) $v)->all();
        }
        if ($type === 'country') {
            return $employed->where('employees.country', strtoupper($id))->pluck('employees.id')->map(fn ($v) => (int) $v)->all();
        }
        if ($type === 'employee_group') {
            return $employed->whereIn('employees.id', DB::table('employee_group_members')->where('tenant_id', $tenant)->where('group_id', $id)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->select('employee_id'))->pluck('employees.id')->map(fn ($v) => (int) $v)->all();
        }
        if (isset(self::ORGANIZATION[$type])) {
            $column = self::ORGANIZATION[$type][1];

            return $employed->whereIn('employees.id', DB::table('employee_assignments')->where('tenant_id', $tenant)->where($column, $id)->where('effective_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->select('employee_id'))->pluck('employees.id')->map(fn ($v) => (int) $v)->all();
        }

        return [];
    }

    /** True when the referenced target exists in this tenant. */
    public static function exists(string $type, string $id): bool
    {
        $tenant = app(TenantContext::class)->id;
        if ($type === 'tenant') {
            return true;
        }
        if ($type === 'country') {
            return (bool) preg_match('/^[A-Z]{2}$/', $id);
        }
        $table = $type === 'employee' ? 'employees' : ($type === 'employee_group' ? 'employee_groups' : (self::ORGANIZATION[$type][0] ?? null));

        return $table !== null && ctype_digit($id) && DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->exists();
    }
}
