<?php

namespace App\Domain\People\Services;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\ReportingRelationship;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated reporting relationships (primary, functional, dotted-line, HR partner, custom types)
 * and the reusable queries every other module uses: getManager, getDirectReports, getReportingChain,
 * getAllSubordinates, getRelationship. Data scopes (DataScope) rely on the same current-relationship rules.
 */
final class ReportingService
{
    public const TYPES = ['primary', 'functional', 'dotted_line', 'hr_partner'];

    /** Relationship types that form a hierarchy and must stay acyclic. */
    private const ACYCLIC = ['primary', 'functional', 'dotted_line'];

    private const MAX_DEPTH = 30;

    public function __construct(private DomainEvents $events) {}

    public function getManager(Employee $employee, string $type = 'primary', ?string $date = null): ?Employee
    {
        $row = $this->current($type, $date)->where('employee_id', $employee->id)->orderByDesc('effective_from')->first();

        return $row ? Employee::query()->find($row->manager_id) : null;
    }

    /** @return Collection<int,Employee> */
    public function getDirectReports(Employee $manager, string $type = 'primary', ?string $date = null): Collection
    {
        $ids = $this->current($type, $date)->where('manager_id', $manager->id)->pluck('employee_id');

        return $ids->isEmpty() ? collect() : Employee::query()->whereIn('id', $ids)->get();
    }

    /** Managers from nearest to furthest, cycle-safe. @return Collection<int,Employee> */
    public function getReportingChain(Employee $employee, string $type = 'primary', ?string $date = null, int $limit = self::MAX_DEPTH): Collection
    {
        $chain = collect();
        $seen = [$employee->id => true];
        $cursor = $employee;
        while ($chain->count() < $limit && ($manager = $this->getManager($cursor, $type, $date)) && ! isset($seen[$manager->id])) {
            $chain->push($manager);
            $seen[$manager->id] = true;
            $cursor = $manager;
        }

        return $chain;
    }

    /** @return array<int,int> employee ids of every direct and indirect report (breadth-first, cycle-safe). */
    public function getAllSubordinates(Employee $manager, string $type = 'primary', ?string $date = null): array
    {
        $seen = [$manager->id => true];
        $frontier = [$manager->id];
        $out = [];
        for ($depth = 0; $frontier && $depth < self::MAX_DEPTH; $depth++) {
            $next = $this->current($type, $date)->whereIn('manager_id', $frontier)->pluck('employee_id')->all();
            $frontier = [];
            foreach ($next as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $id;
                    $frontier[] = $id;
                }
            }
        }

        return $out;
    }

    /** Describes what $other is to $subject: self | manager | indirect_manager | direct_report | subordinate | functional_manager | dotted_line_manager | hr_partner | none. */
    public function getRelationship(Employee $subject, Employee $other, ?string $date = null): string
    {
        if ($subject->id === $other->id) {
            return 'self';
        }
        $rows = $this->current(null, $date)->where(fn ($q) => $q->where(fn ($w) => $w->where('employee_id', $subject->id)->where('manager_id', $other->id))->orWhere(fn ($w) => $w->where('employee_id', $other->id)->where('manager_id', $subject->id)))->get();
        foreach ($rows as $row) {
            $forward = $row->employee_id === $subject->id; // other manages subject
            if ($row->type === 'primary') {
                return $forward ? 'manager' : 'direct_report';
            }
            if ($row->type === 'functional') {
                return $forward ? 'functional_manager' : 'functional_report';
            }
            if ($row->type === 'dotted_line') {
                return $forward ? 'dotted_line_manager' : 'dotted_line_report';
            }
            if ($row->type === 'hr_partner') {
                return $forward ? 'hr_partner' : 'hr_partner_of';
            }
        }
        if ($this->getReportingChain($subject, 'primary', $date)->contains('id', $other->id)) {
            return 'indirect_manager';
        }

        return in_array($other->id, $this->getAllSubordinates($subject, 'primary', $date), true) ? 'subordinate' : 'none';
    }

    /**
     * Sets (or clears when $manager is null) the manager of one relationship type from a date.
     * Closes the covering row at date-1 and starts a new one; refuses cycles and self-management.
     */
    public function assign(Employee $employee, ?Employee $manager, string $type, string $effectiveFrom, ?string $reason = null, string $source = 'manual'): ?ReportingRelationship
    {
        abort_unless(in_array($type, self::TYPES, true) || $this->customType($type), 422, 'Unknown relationship type.');

        return DB::transaction(function () use ($employee, $manager, $type, $effectiveFrom, $reason, $source) {
            // Serialize concurrent hierarchy edits for the tenant so two edits cannot jointly create a cycle.
            DB::table('tenants')->where('id', $employee->tenant_id)->lockForUpdate()->first();
            $from = CarbonImmutable::parse($effectiveFrom)->toDateString();
            abort_if($manager && $manager->id === $employee->id, 422, 'An employee cannot report to themselves.');
            abort_if($from < $employee->joined_on->toDateString(), 422, 'A reporting change cannot start before the employee joined.');
            if ($manager) {
                abort_if($manager->separated_on && $manager->separated_on->toDateString() < $from, 422, 'The selected manager will have left by that date.');
                abort_if(in_array($manager->status, ['separated', 'archived', 'inactive'], true) && ! $manager->separated_on, 422, 'Choose a manager who is currently employed.');
                if (in_array($type, self::ACYCLIC, true)) {
                    $this->assertNoCycle($employee, $manager, $type, $from);
                }
            }
            $rows = ReportingRelationship::query()->where('employee_id', $employee->id)->where('type', $type)->orderBy('effective_from')->get();
            abort_if($rows->contains(fn ($r) => $r->effective_from->toDateString() === $from), 422, 'A reporting record already starts on this date. Choose a different effective date.');
            $base = $rows->first(fn ($r) => $r->effective_from->toDateString() <= $from && ($r->effective_to === null || $r->effective_to->toDateString() >= $from));
            $next = $rows->first(fn ($r) => $r->effective_from->toDateString() > $from);
            abort_if($base && $manager && $base->manager_id === $manager->id, 422, 'This manager is already assigned for that date.');
            $previous = $base ? Employee::query()->with('person')->find($base->manager_id) : null;
            if ($base) {
                $base->effective_to = CarbonImmutable::parse($from)->subDay()->toDateString();
                $base->save();
            }
            $row = $manager ? ReportingRelationship::create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => $type, 'effective_from' => $from,
                'effective_to' => $next ? CarbonImmutable::parse($next->effective_from)->subDay()->toDateString() : null, 'reason' => $reason, 'source' => $source, 'initiated_by' => auth()->id()]) : null;

            if ($previous || $manager) {
                $manager?->loadMissing('person');
                $label = str_replace('_', ' ', $type === 'primary' ? 'reporting manager' : $type.' manager');
                $this->events->record('ManagerChanged', ['employee' => $employee, 'subject' => ['relationship', $row?->id ?? $base->id], 'effective_date' => $from, 'classification' => Classification::PUBLIC_INTERNAL,
                    'summary' => ucfirst($label).' changed from '.($previous?->person?->display_name ?? 'none').' to '.($manager?->person?->display_name ?? 'none').'.',
                    'payload' => ['type' => $type, 'from_manager_id' => $previous?->id, 'to_manager_id' => $manager?->id, 'reason' => $reason, 'effective_date' => $from, 'future_dated' => $from > TenantClock::today()]]);
            }
            Audit::record('manager_changed', 'people', 'employee:'.$employee->uuid, ['type' => $type, 'effective_from' => $from]);

            return $row;
        });
    }

    /** @return array<int,array<string,mixed>> relationship history of one employee (all types), newest first. */
    public function history(Employee $employee): array
    {
        $rows = ReportingRelationship::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->get();
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $rows->pluck('manager_id'))->pluck('people.display_name', 'employees.id');
        $uuids = DB::table('employees')->whereIn('id', $rows->pluck('manager_id'))->pluck('uuid', 'id');

        return $rows->map(fn ($r) => ['type' => $r->type, 'manager_uuid' => $uuids[$r->manager_id] ?? null, 'manager_name' => $names[$r->manager_id] ?? null,
            'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString(), 'reason' => $r->reason])->all();
    }

    private function assertNoCycle(Employee $employee, Employee $manager, string $type, string $date): void
    {
        $seen = [];
        $cursor = $manager;
        for ($i = 0; $cursor && $i < self::MAX_DEPTH + 5; $i++) {
            abort_if($cursor->id === $employee->id, 422, 'This would create a circular reporting line.');
            if (isset($seen[$cursor->id])) {
                break;
            }
            $seen[$cursor->id] = true;
            $cursor = $this->getManager($cursor, $type, $date);
        }
    }

    private function customType(string $code): bool
    {
        return DB::table('lookup_values')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('kind', 'relationship_type')->where('code', $code)->where('active', true)->exists();
    }

    /** Query over relationships valid on a date (default today). $type null = every type. */
    private function current(?string $type, ?string $date)
    {
        $date ??= TenantClock::today();

        return ReportingRelationship::query()->when($type, fn ($q) => $q->where('type', $type))->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
