<?php

namespace App\Domain\People\Services;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated job information (slowly-changing dimension, type 2).
 *
 * Invariant: for one employee, assignment periods never overlap and an earlier row is only ever *closed*
 * (effective_to set); no other column of a historical row is edited. A change at date D closes the row that
 * covers D at D-1 and inserts a new row from D, ending just before the next future-dated row if one exists.
 */
final class AssignmentService
{
    private const LABELS = [
        'legal_entity_id' => 'Legal entity', 'business_unit_id' => 'Business unit', 'department_id' => 'Department', 'team_id' => 'Team', 'cost_center_id' => 'Cost center',
        'designation_id' => 'Designation', 'position_id' => 'Position', 'grade_id' => 'Grade', 'level_id' => 'Level', 'employee_type_id' => 'Employee type', 'location_id' => 'Location',
        'employment_category' => 'Employment category', 'work_mode' => 'Work mode',
    ];

    private const CONFIDENTIAL = ['position_id', 'grade_id', 'level_id', 'cost_center_id', 'employment_category'];

    private const PROMOTION_FIELDS = ['designation_id', 'grade_id', 'level_id', 'position_id'];

    public function __construct(private DomainEvents $events) {}

    /**
     * @param  array<string,mixed>  $changes  organization dimension ids / employment_category / work_mode to change (omit = keep)
     * @param  array{change_type?:string,reason?:string,source?:string,comments?:string,approved_by?:int,approval_ref?:string}  $meta
     */
    public function change(Employee $employee, array $changes, string $effectiveFrom, array $meta = []): Assignment
    {
        return DB::transaction(function () use ($employee, $changes, $effectiveFrom, $meta) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = CarbonImmutable::parse($effectiveFrom)->toDateString();
            abort_if($from < $employee->joined_on->toDateString(), 422, 'A job change cannot start before the employee joined.');
            abort_if($employee->separated_on && $from > $employee->separated_on->toDateString(), 422, 'A job change cannot start after the last working date.');
            $this->assertReferences($changes);

            $rows = Assignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
            $base = $rows->first(fn ($r) => $r->effective_from->toDateString() <= $from && ($r->effective_to === null || $r->effective_to->toDateString() >= $from));
            abort_if($rows->contains(fn ($r) => $r->effective_from->toDateString() === $from), 422, 'A job record already starts on this date. Choose a different effective date.');
            $next = $rows->first(fn ($r) => $r->effective_from->toDateString() > $from);

            $attributes = array_fill_keys(array_keys(self::LABELS), null);
            if ($base) {
                foreach ($attributes as $key => $_) {
                    $attributes[$key] = $base->{$key};
                }
            } else {
                $attributes['work_mode'] = 'onsite';
            }
            $before = $attributes;
            foreach ($changes as $key => $value) {
                if (array_key_exists($key, $attributes)) {
                    $attributes[$key] = $value === '' ? null : $value;
                }
            }
            $diff = array_filter(array_keys(self::LABELS), fn ($k) => ($before[$k] ?? null) != ($attributes[$k] ?? null));
            abort_if($base && ! $diff, 422, 'Nothing changed. Update at least one job field.');

            if ($base) {
                $base->effective_to = CarbonImmutable::parse($from)->subDay()->toDateString();
                $base->save();
            }
            $changeType = $meta['change_type'] ?? ($base ? $this->detectType($diff) : 'initial');
            $row = Assignment::create($attributes + [
                'employee_id' => $employee->id, 'effective_from' => $from,
                'effective_to' => $next ? CarbonImmutable::parse($next->effective_from)->subDay()->toDateString() : null,
                'change_type' => $changeType, 'reason' => $meta['reason'] ?? null, 'source' => $meta['source'] ?? 'manual',
                'initiated_by' => auth()->id(), 'approved_by' => $meta['approved_by'] ?? null, 'comments' => $meta['comments'] ?? null, 'approval_ref' => $meta['approval_ref'] ?? null,
            ]);

            if ($base) {
                $this->announce($employee, $row, $diff, $before, $attributes, $changeType, $from, $meta['reason'] ?? null);
            }
            Audit::record('job_changed', 'people', 'employee:'.$employee->uuid, ['effective_from' => $from, 'fields' => array_values($diff)]);

            return $row;
        });
    }

    public function current(Employee $employee, ?string $date = null): ?Assignment
    {
        return $employee->assignmentOn($date);
    }

    /** Full history, oldest first, with organization names resolved for display. */
    public function history(Employee $employee): array
    {
        $rows = Assignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $names = $this->names($rows);

        return $rows->map(function ($row) use ($names) {
            $out = $row->only(['effective_from', 'effective_to', 'employment_category', 'work_mode', 'change_type', 'reason', 'source', 'comments']);
            foreach (array_keys(Assignment::DIMENSIONS) as $column) {
                $out[$column] = $row->{$column};
                $out[str_replace('_id', '', $column)] = $row->{$column} ? ($names[$column][$row->{$column}] ?? null) : null;
            }

            return $out;
        })->all();
    }

    /** Detect overlaps and gaps in an employee's assignment history (data-quality input). */
    public function integrityProblems(Employee $employee): array
    {
        $rows = Assignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $problems = [];
        foreach ($rows as $i => $row) {
            $next = $rows[$i + 1] ?? null;
            if (! $next) {
                continue;
            }
            $end = $row->effective_to?->toDateString();
            if ($end === null || $end >= $next->effective_from->toDateString()) {
                $problems[] = ['type' => 'overlap', 'at' => $next->effective_from->toDateString()];
            } elseif (CarbonImmutable::parse($end)->addDay()->toDateString() !== $next->effective_from->toDateString()) {
                $problems[] = ['type' => 'gap', 'at' => $end];
            }
        }

        return $problems;
    }

    private function assertReferences(array $changes): void
    {
        foreach (Assignment::DIMENSIONS as $column => $table) {
            $id = $changes[$column] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            abort_unless(DB::table($table)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('id', $id)->where('active', true)->exists(), 422, 'Choose an active '.strtolower(self::LABELS[$column]).' from your organization.');
        }
        if (isset($changes['work_mode'])) {
            abort_unless(in_array($changes['work_mode'], ['onsite', 'remote', 'hybrid'], true), 422, 'Work mode must be onsite, remote or hybrid.');
        }
    }

    private function detectType(array $diff): string
    {
        if (array_intersect($diff, self::PROMOTION_FIELDS)) {
            return 'promotion';
        }

        return array_intersect($diff, ['department_id', 'location_id', 'legal_entity_id', 'business_unit_id', 'team_id']) ? 'transfer' : 'update';
    }

    private function announce(Employee $employee, Assignment $row, array $diff, array $before, array $after, string $type, string $date, ?string $reason): void
    {
        $names = $this->names(collect([(object) $before, (object) $after]));
        $lines = [];
        foreach ($diff as $key) {
            $label = self::LABELS[$key];
            $table = isset(Assignment::DIMENSIONS[$key]);
            $old = $table ? ($before[$key] ? ($names[$key][$before[$key]] ?? '—') : 'none') : ($before[$key] ?? 'none');
            $new = $table ? ($after[$key] ? ($names[$key][$after[$key]] ?? '—') : 'none') : ($after[$key] ?? 'none');
            $lines[] = "$label changed from $old to $new.";
        }
        $class = array_intersect($diff, self::CONFIDENTIAL) ? Classification::HR_CONFIDENTIAL : Classification::PUBLIC_INTERNAL;
        $event = match ($type) {
            'promotion' => 'EmployeePromoted', 'transfer' => 'EmployeeTransferred', default => 'EmployeeUpdated',
        };
        $this->events->record($event, ['employee' => $employee, 'subject' => ['assignment', $row->id], 'summary' => implode(' ', $lines), 'classification' => $class, 'effective_date' => $date,
            'payload' => ['fields' => array_values($diff), 'before' => $before, 'after' => $after, 'reason' => $reason, 'effective_date' => $date, 'future_dated' => $date > TenantClock::today()]]);
    }

    /** @return array<string,array<int,string>> column => [id => name] */
    private function names($rows): array
    {
        $out = [];
        foreach (Assignment::DIMENSIONS as $column => $table) {
            $ids = collect($rows)->pluck($column)->filter()->unique()->values();
            $out[$column] = $ids->isEmpty() ? [] : DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        return $out;
    }
}
