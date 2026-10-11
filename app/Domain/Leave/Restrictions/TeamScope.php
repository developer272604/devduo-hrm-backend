<?php

namespace App\Domain\Leave\Restrictions;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Who works alongside an employee on a date (as-of organization data, never "now"), and who among them is away.
 * Used by staffing restrictions, the impact preview and the team availability service.
 */
final class TeamScope
{
    public const DIMENSIONS = ['team' => 'team_id', 'department' => 'department_id', 'business_unit' => 'business_unit_id', 'location' => 'location_id',
        'legal_entity' => 'legal_entity_id', 'employee_type' => 'employee_type_id'];

    public const FRACTION = ['full_day' => 1.0, 'first_half' => 0.5, 'second_half' => 0.5, 'quarter' => 0.25];

    /** @return array<int,int> working employees (including $employeeId) sharing the dimension value on the date */
    public function peers(int $employeeId, string $dimension, string $date): array
    {
        $tenant = app(TenantContext::class)->id;
        if ($dimension === 'tenant' || ! isset(self::DIMENSIONS[$dimension])) {
            return $this->working(DB::table('employees')->where('tenant_id', $tenant), $date);
        }
        $column = self::DIMENSIONS[$dimension];
        $value = $this->valueOf($employeeId, $column, $date);
        if ($value === null) {
            return [$employeeId];
        }
        $query = DB::table('employees')->where('employees.tenant_id', $tenant)->whereIn('employees.id', fn ($q) => $q->select('employee_id')->from('employee_assignments')
            ->where('tenant_id', $tenant)->where($column, $value)->where('effective_from', '<=', $date)->where(fn ($qq) => $qq->whereNull('effective_to')->orWhere('effective_to', '>=', $date)));

        return $this->working($query, $date);
    }

    public function valueOf(int $employeeId, string $column, string $date): ?int
    {
        $v = DB::table('employee_assignments')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->value($column);

        return $v === null ? null : (int) $v;
    }

    /** @return array<int,float> employee id => fraction of the day away (1.0 = whole day) for pending+approved leave */
    public function away(array $employeeIds, string $date, ?int $ignoreRequestId = null, bool $includePending = true): array
    {
        if (! $employeeIds) {
            return [];
        }
        $statuses = $includePending ? ['pending', 'approved', 'partially_cancelled'] : ['approved', 'partially_cancelled'];
        $rows = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.tenant_id', app(TenantContext::class)->id)->where('s.leave_date', $date)->where('s.status', 'active')->where('s.charged', true)
            ->whereIn('s.employee_id', $employeeIds)->whereIn('r.status', $statuses)->when($ignoreRequestId, fn ($q) => $q->where('r.id', '!=', $ignoreRequestId))
            ->get(['s.employee_id', 's.portion', 's.minutes']);
        $out = [];
        foreach ($rows as $row) {
            $f = $row->portion === 'hours' ? min(1.0, ((int) $row->minutes) / 480) : (self::FRACTION[$row->portion] ?? 1.0);
            $out[$row->employee_id] = min(1.0, ($out[$row->employee_id] ?? 0.0) + $f);
        }

        return $out;
    }

    private function working($query, string $date): array
    {
        return $query->where('employees.joined_on', '<=', $date)->where(fn ($q) => $q->whereNull('employees.separated_on')->orWhere('employees.separated_on', '>=', $date))
            ->whereNull('employees.deleted_at')->whereNotIn('employees.status', ['archived', 'preboarding'])->orderBy('employees.id')->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }
}
