<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\Classification;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Employee timeline: built ONLY from domain_events (no second hand-maintained history).
 *
 * Visibility: the viewer's relation to the employee is computed once, FieldAccess::viewClass decides for every
 * classification which entries are visible, and the query keeps only those classes - so a hidden class can never
 * leak into a page, a count or a page number. Details (before/after) are rebuilt from the payload field by field and
 * each field is checked again, so an event that is visible never carries a value of a hidden field.
 */
final class TimelineService
{
    private const ASSIGNMENT_FIELDS = [
        'legal_entity_id' => 'job.legal_entity', 'business_unit_id' => 'job.business_unit', 'department_id' => 'job.department', 'team_id' => 'job.team',
        'cost_center_id' => 'job.cost_center', 'designation_id' => 'job.designation', 'position_id' => 'job.position', 'grade_id' => 'job.grade', 'level_id' => 'job.level',
        'employee_type_id' => 'job.employee_type', 'location_id' => 'job.location', 'employment_category' => 'job.employment_category', 'work_mode' => 'job.work_mode',
    ];

    private const EMPLOYMENT_FIELDS = [
        'work_email' => 'employee.work_email', 'work_phone' => 'employee.work_phone', 'worker_type' => 'employee.worker_type', 'employment_start_date' => 'employee.joined_on',
        'probation_applicable' => 'employee.probation', 'probation_start' => 'employee.probation', 'probation_end' => 'employee.probation', 'probation_extensions' => 'employee.probation',
        'confirmation_date' => 'employee.probation', 'contract_start' => 'employee.contract', 'contract_end' => 'employee.contract', 'notice_period_days' => 'employee.notice_period_days',
        'retirement_date' => 'employee.retirement_date', 'timezone' => 'employee.status', 'country' => 'employee.status', 'separated_on' => 'employee.separated_on',
    ];

    public function __construct(private FieldAccess $access) {}

    /** @param array{group?:?string,from?:?string,to?:?string,type?:?string} $filters */
    public function page(User $viewer, Employee $employee, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $relation = $this->access->relation($viewer, $employee); // once per request
        $query = $this->visibleQuery($viewer, $employee, $relation, $filters);
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
        $page->setCollection(collect($this->present($page->getCollection()->all(), $viewer, $employee, $relation)));

        return $page;
    }

    /** The last N visible entries (Employee 360 "what changed recently"). @return array<int,array<string,mixed>> */
    public function recent(User $viewer, Employee $employee, int $limit = 10, ?array $relation = null): array
    {
        $relation ??= $this->access->relation($viewer, $employee);
        $rows = $this->visibleQuery($viewer, $employee, $relation, [])->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->all();

        return $this->present($rows, $viewer, $employee, $relation);
    }

    /** @return array<int,string> classifications whose entries the viewer may see for this employee. */
    public function visibleClasses(User $viewer, Employee $employee, ?array $relation = null): array
    {
        $relation ??= $this->access->relation($viewer, $employee);

        return array_values(array_filter(Classification::ALL, fn ($class) => $this->access->viewClass($viewer, $class, $employee, $relation) !== FieldAccess::HIDDEN));
    }

    private function visibleQuery(User $viewer, Employee $employee, array $relation, array $filters)
    {
        $classes = array_values(array_filter(Classification::ALL, fn ($class) => $this->access->viewClass($viewer, $class, $employee, $relation) !== FieldAccess::HIDDEN));
        $q = DB::table('domain_events')->where('tenant_id', $employee->tenant_id)
            ->where(fn ($w) => $w->where('employee_id', $employee->id)->orWhere(fn ($p) => $p->whereNull('employee_id')->where('person_id', $employee->person_id)));
        $classes ? $q->whereIn('classification', $classes) : $q->whereRaw('1 = 0');

        if (! empty($filters['type'])) {
            $q->whereIn('type', (array) $filters['type']);
        }
        if (! empty($filters['group'])) {
            $this->applyGroup($q, (string) $filters['group']);
        }
        if (! empty($filters['from'])) {
            $q->whereRaw('COALESCE(effective_date, DATE(occurred_at)) >= ?', [$filters['from']]);
        }
        if (! empty($filters['to'])) {
            $q->whereRaw('COALESCE(effective_date, DATE(occurred_at)) <= ?', [$filters['to']]);
        }

        return $q;
    }

    private function applyGroup($q, string $group): void
    {
        $known = array_keys(DomainEventTypes::all());
        $patterns = DomainEventTypes::stemPatterns($group);
        if ($group === 'other') {
            $q->whereNotIn('type', $known);
            foreach (array_merge(...array_map(fn ($c) => DomainEventTypes::stemPatterns($c), array_keys(DomainEventTypes::categories()))) as $pattern) {
                $q->where('type', 'not like', $pattern);
            }

            return;
        }
        $types = DomainEventTypes::typesIn($group);
        $q->where(function ($w) use ($types, $patterns) {
            $w->whereIn('type', $types ?: ['__none__']);
            foreach ($patterns as $pattern) {
                $w->orWhere('type', 'like', $pattern);
            }
        });
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function present(array $rows, User $viewer, Employee $employee, array $relation): array
    {
        if (! $rows) {
            return [];
        }
        $today = TenantClock::today();
        $knowsActors = $viewer->allows('people.personal.view') || $viewer->allows('people.confidential.view');
        $actors = DB::table('users')->whereIn('id', collect($rows)->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $payloads = [];
        foreach ($rows as $row) {
            $payloads[$row->id] = is_string($row->payload) ? (json_decode($row->payload, true) ?: []) : [];
        }
        $names = $this->dimensionNames($payloads);
        $managers = $this->managerNames($payloads);
        $managerVisible = $this->access->viewClass($viewer, Classification::PUBLIC_INTERNAL, $employee, $relation, 'manager') !== FieldAccess::HIDDEN;

        $out = [];
        foreach ($rows as $row) {
            $meta = DomainEventTypes::describe($row->type);
            $actorName = $row->actor_id && ($knowsActors || (int) $row->actor_id === $viewer->id) ? ($actors[$row->actor_id] ?? null) : null;
            $out[] = [
                'uuid' => $row->uuid, 'type' => $row->type, 'category' => $meta['category'], 'icon' => $meta['icon'], 'label' => $meta['label'], 'summary' => $row->summary,
                'occurred_at' => $row->occurred_at ? \Carbon\CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String() : null,
                'effective_date' => $row->effective_date ? substr((string) $row->effective_date, 0, 10) : null,
                'future_dated' => $row->effective_date ? substr((string) $row->effective_date, 0, 10) > $today : false,
                'actor_name' => $actorName,
                'details' => $this->details($payloads[$row->id], $viewer, $employee, $relation, $names, $managers, $managerVisible),
            ];
        }

        return $out;
    }

    private function details(array $p, User $viewer, Employee $employee, array $relation, array $names, array $managers, bool $managerVisible): array
    {
        $d = [];
        foreach (['reason', 'from', 'to', 'type'] as $key) {
            if (isset($p[$key]) && is_scalar($p[$key])) {
                $d[$key] = $p[$key];
            }
        }
        if ($managerVisible) {
            foreach (['from_manager_id' => 'from_manager', 'to_manager_id' => 'to_manager'] as $key => $label) {
                if (! empty($p[$key]) && isset($managers[$p[$key]])) {
                    $d[$label] = $managers[$p[$key]];
                }
            }
        }
        $map = self::ASSIGNMENT_FIELDS + self::EMPLOYMENT_FIELDS;
        $visible = fn (string $column) => ! isset($map[$column]) || $this->fieldVisible($viewer, $employee, $relation, $map[$column]);
        $fields = isset($p['fields']) && is_array($p['fields']) ? array_values(array_filter($p['fields'], fn ($f) => is_string($f) && $this->knownFieldVisible($f, $map, $viewer, $employee, $relation))) : null;
        if ($fields !== null) {
            $d['fields'] = array_map(fn ($f) => str_replace('_id', '', $f), $fields);
        }
        foreach (['before', 'after'] as $side) {
            if (empty($p[$side]) || ! is_array($p[$side])) {
                continue;
            }
            $values = [];
            foreach ($p[$side] as $column => $value) {
                if (! isset($map[$column]) || ! $visible($column) || ($fields !== null && ! in_array($column, $fields, true))) {
                    continue; // unknown or hidden fields are never echoed
                }
                $values[str_replace('_id', '', $column)] = isset(Assignment::DIMENSIONS[$column]) ? ($value ? ($names[$column][$value] ?? null) : null) : $value;
            }
            if ($values) {
                $d[$side] = $values;
            }
        }
        if (isset($p['details']) && is_array($p['details'])) {
            $d['extra'] = $p['details']; // module-authored payload meant for anyone who can see the entry
        }

        return $d;
    }

    private function knownFieldVisible(string $column, array $map, User $viewer, Employee $employee, array $relation): bool
    {
        if (isset($map[$column])) {
            return $this->fieldVisible($viewer, $employee, $relation, $map[$column]);
        }
        $field = 'person.'.$column;

        return \App\Domain\Access\FieldRegistry::known($field) ? $this->fieldVisible($viewer, $employee, $relation, $field) : true;
    }

    private function fieldVisible(User $viewer, Employee $employee, array $relation, string $field): bool
    {
        return $this->access->viewClass($viewer, PeopleSettings::classification($field), $employee, $relation, \App\Domain\Access\FieldRegistry::privacyKey($field)) !== FieldAccess::HIDDEN;
    }

    /** @return array<string,array<int,string>> dimension column => [id => name] for every id mentioned in before/after of the page. */
    private function dimensionNames(array $payloads): array
    {
        $ids = [];
        foreach ($payloads as $p) {
            foreach (['before', 'after'] as $side) {
                foreach (Assignment::DIMENSIONS as $column => $table) {
                    if (! empty($p[$side][$column])) {
                        $ids[$column][] = $p[$side][$column];
                    }
                }
            }
        }
        $out = [];
        foreach ($ids as $column => $list) {
            $out[$column] = DB::table(Assignment::DIMENSIONS[$column])->whereIn('id', array_unique($list))->pluck('name', 'id')->all();
        }

        return $out;
    }

    /** @return array<int,array{uuid:string,name:string}> */
    private function managerNames(array $payloads): array
    {
        $ids = [];
        foreach ($payloads as $p) {
            foreach (['from_manager_id', 'to_manager_id'] as $key) {
                if (! empty($p[$key])) {
                    $ids[] = $p[$key];
                }
            }
        }
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', array_unique($ids))->get(['employees.id', 'employees.uuid', 'people.display_name']) as $row) {
            $out[$row->id] = ['uuid' => $row->uuid, 'name' => $row->display_name];
        }

        return $out;
    }
}
