<?php

namespace App\Domain\People\Memory;

use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Organization Memory: "where did this person sit, and who did they report to, on date X".
 *
 * PUBLIC service for other modules (attendance, leave, payroll, audits). It never reads "current" data: every answer
 * is resolved from the effective-dated rows as of the requested date, so a later transfer or manager change cannot
 * rewrite history. The service is deliberately NOT permission-aware (callers are server-side modules); the HTTP
 * endpoint filters every part of the snapshot through FieldAccess before returning it.
 *
 * snapshot() returns:
 *   employee_uuid, person_uuid, date
 *   employed        joined on/before the date and not past the last working date
 *   status          status code in force on the date (employee_status_history), null when unknown
 *   assignment      null | {effective_from, effective_to, change_type, reason, work_mode, employment_category, ids: {<dimension>_id: id}, names: {<dimension>: name}}
 *   managers        {<relationship type>: [{employee_uuid, employee_id, name, effective_from, effective_to, reason}]}
 *   policies        {<kind>: {reference_id, effective_from, effective_to}}
 */
final class OrganizationMemory
{
    public function snapshot(Employee $employee, string $date): array
    {
        $date = \Carbon\CarbonImmutable::parse($date)->toDateString();
        $tenant = $employee->tenant_id;
        $employee->loadMissing('person');

        $employed = $employee->joined_on->toDateString() <= $date && ($employee->separated_on === null || $employee->separated_on->toDateString() >= $date);

        $status = DB::table('employee_status_history')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')->orderByDesc('id')->first();

        return [
            'employee_uuid' => $employee->uuid, 'person_uuid' => $employee->person->uuid, 'date' => $date, 'employed' => $employed,
            'status' => $status?->to_status,
            'assignment' => $this->assignment($employee, $date),
            'managers' => $this->managers($employee, $date),
            'policies' => $this->policies($employee, $date),
        ];
    }

    /** The employment of a person that covers the date (rehire-safe), or null when the person was not employed that day. */
    public function employmentOn(int $personId, string $date): ?Employee
    {
        $date = \Carbon\CarbonImmutable::parse($date)->toDateString();

        return Employee::query()->where('person_id', $personId)->where('joined_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('separated_on')->orWhere('separated_on', '>=', $date))->orderByDesc('employment_sequence')->first();
    }

    /** Snapshot of the person's employment on the date, or null when none covers it. */
    public function snapshotForPerson(int $personId, string $date): ?array
    {
        $employee = $this->employmentOn($personId, $date);

        return $employee ? $this->snapshot($employee, $date) : null;
    }

    /**
     * Headcount on a date grouped by an organization dimension. Counts employments that had joined and not passed their
     * last working date. $dimension: department|location|legal_entity|business_unit|team|employee_type|designation.
     *
     * @param  callable|null  $constrain  receives the base query (alias e = employees) to add data-scope restrictions
     * @return array<int,array{id:?int,name:string,count:int}>
     */
    public function headcount(int $tenantId, string $date, string $dimension, ?callable $constrain = null): array
    {
        $date = \Carbon\CarbonImmutable::parse($date)->toDateString();
        $columns = ['department' => ['department_id', 'departments'], 'location' => ['location_id', 'locations'], 'legal_entity' => ['legal_entity_id', 'legal_entities'],
            'business_unit' => ['business_unit_id', 'business_units'], 'team' => ['team_id', 'teams'], 'employee_type' => ['employee_type_id', 'employee_types'], 'designation' => ['designation_id', 'designations']];
        abort_unless(isset($columns[$dimension]), 422, 'Unknown breakdown.');
        [$column, $table] = $columns[$dimension];

        $q = DB::table('employees as e')->leftJoin('employee_assignments as a', function ($j) use ($date) {
            $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $date));
        })->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->where('e.joined_on', '<=', $date)
            ->where(fn ($w) => $w->whereNull('e.separated_on')->orWhere('e.separated_on', '>=', $date));
        if ($constrain) {
            $constrain($q);
        }
        $rows = $q->selectRaw("a.$column as dimension_id, COUNT(DISTINCT e.id) as total")->groupBy("a.$column")->get();
        $names = DB::table($table)->where('tenant_id', $tenantId)->whereIn('id', $rows->pluck('dimension_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => ['id' => $r->dimension_id ? (int) $r->dimension_id : null, 'name' => $r->dimension_id ? ($names[$r->dimension_id] ?? 'Unknown') : 'Unassigned', 'count' => (int) $r->total])
            ->sortByDesc('count')->values()->all();
    }

    private function assignment(Employee $employee, string $date): ?array
    {
        $row = Assignment::query()->where('employee_id', $employee->id)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
        if (! $row) {
            return null;
        }
        $ids = [];
        $names = [];
        foreach (Assignment::DIMENSIONS as $column => $table) {
            $ids[$column] = $row->{$column};
            $names[str_replace('_id', '', $column)] = $row->{$column} ? DB::table($table)->where('id', $row->{$column})->value('name') : null;
        }

        return ['effective_from' => $row->effective_from->toDateString(), 'effective_to' => $row->effective_to?->toDateString(), 'change_type' => $row->change_type, 'reason' => $row->reason,
            'work_mode' => $row->work_mode, 'employment_category' => $row->employment_category, 'ids' => $ids, 'names' => $names];
    }

    private function managers(Employee $employee, string $date): array
    {
        $rows = DB::table('employee_reporting_relationships as r')->join('employees as m', 'm.id', '=', 'r.manager_id')->join('people as p', 'p.id', '=', 'm.person_id')
            ->where('r.tenant_id', $employee->tenant_id)->where('r.employee_id', $employee->id)->where('r.effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $date))->orderBy('r.effective_from')
            ->get(['r.type', 'r.effective_from', 'r.effective_to', 'r.reason', 'm.uuid', 'm.id as employee_id', 'p.display_name']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->type][] = ['employee_uuid' => $row->uuid, 'employee_id' => (int) $row->employee_id, 'name' => $row->display_name, 'effective_from' => substr($row->effective_from, 0, 10),
                'effective_to' => $row->effective_to ? substr($row->effective_to, 0, 10) : null, 'reason' => $row->reason];
        }

        return $out;
    }

    private function policies(Employee $employee, string $date): array
    {
        $out = [];
        $rows = DB::table('employee_policy_references')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderBy('effective_from')->orderBy('id')->get();
        foreach ($rows as $row) {
            $out[$row->kind] = ['reference_id' => (int) $row->reference_id, 'effective_from' => substr($row->effective_from, 0, 10), 'effective_to' => $row->effective_to ? substr($row->effective_to, 0, 10) : null];
        }

        return $out;
    }
}
