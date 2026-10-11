<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Batch-loaded, AS-OF-DATE organization attributes for many employees (Organization Memory): the effective
 * employee_assignments row for each date (never "now"), group memberships active on the date, and the employment row.
 * A fixed number of queries regardless of how many employees are loaded.
 */
final class OrgContext
{
    private const DIMENSIONS = ['team' => 'team_id', 'department' => 'department_id', 'business_unit' => 'business_unit_id', 'location' => 'location_id',
        'employee_type' => 'employee_type_id', 'legal_entity' => 'legal_entity_id'];

    /** @var array<int,array<string,mixed>> */
    private array $employees = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    private array $assignments = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    private array $groups = [];

    /** @var array<int,array{country:?string,timezone:?string,name:string}> */
    private array $locations = [];

    /** @var array<string,array<string,mixed>> */
    private array $memo = [];

    /** @param array<int,int> $employeeIds */
    public static function load(array $employeeIds, string $from, string $to): self
    {
        $self = new self;
        $tenant = app(TenantContext::class)->id;
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('employees')->where('tenant_id', $tenant)->whereIn('id', $chunk)->get() as $row) {
                $self->employees[$row->id] = (array) $row;
            }
            foreach (DB::table('employee_assignments')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)->where('effective_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->orderBy('effective_from', 'desc')->get() as $row) {
                $self->assignments[$row->employee_id][] = (array) $row;
            }
            foreach (DB::table('employee_group_members')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->get() as $row) {
                $self->groups[$row->employee_id][] = (array) $row;
            }
        }
        $locationIds = [];
        foreach ($self->assignments as $rows) {
            foreach ($rows as $r) {
                if ($r['location_id']) {
                    $locationIds[$r['location_id']] = true;
                }
            }
        }
        if ($locationIds) {
            foreach (DB::table('locations')->where('tenant_id', $tenant)->whereIn('id', array_keys($locationIds))->get(['id', 'name', 'metadata']) as $l) {
                $meta = json_decode($l->metadata ?? '[]', true) ?: [];
                $self->locations[$l->id] = ['country' => $meta['country'] ?? null, 'timezone' => $meta['timezone'] ?? null, 'name' => $l->name];
            }
        }

        return $self;
    }

    public function employee(int $employeeId): ?array
    {
        return $this->employees[$employeeId] ?? null;
    }

    public function locationName(?int $locationId): ?string
    {
        return $locationId ? ($this->locations[$locationId]['name'] ?? null) : null;
    }

    /** The effective assignment row of an employee on a date (same rule as Employee::assignmentOn). */
    public function assignmentOn(int $employeeId, string $date): ?array
    {
        foreach ($this->assignments[$employeeId] ?? [] as $row) { // already ordered by effective_from DESC
            if ($row['effective_from'] <= $date && ($row['effective_to'] === null || $row['effective_to'] >= $date)) {
                return $row;
            }
        }

        return null;
    }

    /** Assignment context keyed by target type for one employee on one date. */
    public function at(int $employeeId, string $date): array
    {
        $key = $employeeId.'|'.$date;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $employee = $this->employees[$employeeId] ?? null;
        $assignment = $this->assignmentOn($employeeId, $date);
        $ctx = ['employee' => (string) $employeeId, 'tenant' => '*', 'employee_group' => [], 'assignment' => $assignment, 'timezone' => $employee['timezone'] ?? null];
        foreach (self::DIMENSIONS as $type => $column) {
            $ctx[$type] = $assignment && $assignment[$column] ? (string) $assignment[$column] : null;
        }
        $location = $assignment['location_id'] ?? null;
        $ctx['country'] = ($location ? ($this->locations[$location]['country'] ?? null) : null) ?? ($employee['country'] ?? null);
        $ctx['location_timezone'] = $location ? ($this->locations[$location]['timezone'] ?? null) : null;
        foreach ($this->groups[$employeeId] ?? [] as $g) {
            if (($g['effective_from'] === null || $g['effective_from'] <= $date) && ($g['effective_to'] === null || $g['effective_to'] >= $date)) {
                $ctx['employee_group'][] = (string) $g['group_id'];
            }
        }

        return $this->memo[$key] = $ctx;
    }

    /**
     * Every target (type => ids) any of the loaded employees could match inside the range - used to bound assignment queries.
     *
     * @return array<string,array<int,string>>
     */
    public function targets(): array
    {
        $out = ['employee' => [], 'employee_group' => [], 'country' => []];
        foreach (array_keys(self::DIMENSIONS) as $type) {
            $out[$type] = [];
        }
        foreach ($this->employees as $id => $employee) {
            $out['employee'][] = (string) $id;
            if ($employee['country']) {
                $out['country'][] = $employee['country'];
            }
        }
        foreach ($this->assignments as $rows) {
            foreach ($rows as $row) {
                foreach (self::DIMENSIONS as $type => $column) {
                    if ($row[$column]) {
                        $out[$type][] = (string) $row[$column];
                    }
                }
                $loc = $row['location_id'] ? ($this->locations[$row['location_id']]['country'] ?? null) : null;
                if ($loc) {
                    $out['country'][] = $loc;
                }
            }
        }
        foreach ($this->groups as $rows) {
            foreach ($rows as $row) {
                $out['employee_group'][] = (string) $row['group_id'];
            }
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $out);
    }
}
