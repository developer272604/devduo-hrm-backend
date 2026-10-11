<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use App\Domain\Quality\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Overlapping or gapped effective-dated job records, overlapping reporting relationships of the same type, and rows
 * whose end precedes their start. A cheap in-memory pass over each 500-employee batch finds the suspicious employees;
 * AssignmentService::integrityProblems then confirms the job-history part for exactly those employees.
 */
final class ConflictingEffectiveDatesRule extends PeopleRule
{
    public function __construct(private AssignmentService $assignments) {}

    public function key(): string
    {
        return 'conflicting_effective_dates';
    }

    public function category(): string
    {
        return 'effective_dating';
    }

    public function label(): string
    {
        return 'Conflicting effective dates';
    }

    public function scan(int $tenantId): iterable
    {
        $query = $this->employees($tenantId)->select(['e.id as id']);
        $batch = [];
        foreach ($this->stream($query) as $row) {
            $batch[] = $row->id;
            if (count($batch) >= 500) {
                yield from $this->inspect($tenantId, $batch);
                $batch = [];
            }
        }
        if ($batch) {
            yield from $this->inspect($tenantId, $batch);
        }
    }

    /** @param array<int,int> $ids */
    private function inspect(int $tenantId, array $ids): iterable
    {
        $problems = [];
        $assignments = DB::table('employee_assignments')->where('tenant_id', $tenantId)->whereIn('employee_id', $ids)->orderBy('employee_id')->orderBy('effective_from')->get(['employee_id', 'effective_from', 'effective_to'])->groupBy('employee_id');
        $suspicious = [];
        foreach ($assignments as $employeeId => $rows) {
            if ($this->sequenceProblems($rows->all(), true)) {
                $suspicious[] = $employeeId;
            }
        }
        foreach ($suspicious as $employeeId) {
            $employee = Employee::query()->find($employeeId);
            foreach ($employee ? $this->assignments->integrityProblems($employee) : [] as $p) {
                $problems[$employeeId][] = ['area' => 'job', 'type' => $p['type'], 'at' => $p['at']];
            }
            if (empty($problems[$employeeId])) { // an invalid row range is not part of integrityProblems
                foreach ($this->sequenceProblems($assignments[$employeeId]->all(), true) as $p) {
                    $problems[$employeeId][] = ['area' => 'job'] + $p;
                }
            }
        }

        $relationships = DB::table('employee_reporting_relationships')->where('tenant_id', $tenantId)->whereIn('employee_id', $ids)->orderBy('employee_id')->orderBy('type')->orderBy('effective_from')
            ->get(['employee_id', 'type', 'effective_from', 'effective_to'])->groupBy(fn ($r) => $r->employee_id.'|'.$r->type);
        foreach ($relationships as $group => $rows) {
            [$employeeId, $type] = explode('|', $group, 2);
            foreach ($this->sequenceProblems($rows->all(), false) as $p) {
                $problems[(int) $employeeId][] = ['area' => 'reporting', 'relationship' => $type] + $p;
            }
        }
        ksort($problems);
        foreach ($problems as $employeeId => $list) {
            yield new Finding((int) $employeeId, 'emp:'.$employeeId, 'Conflicting effective dates in job or reporting history',
                'Job or reporting records overlap or have gaps. Historical reports and calculations may use the wrong data until this is corrected.', 'action_required',
                ['employee', (int) $employeeId], ['problems' => $list], Classification::HR_CONFIDENTIAL);
        }
    }

    /**
     * @param  array<int,object>  $rows  sorted by effective_from
     * @return array<int,array{type:string,at:string}>
     */
    private function sequenceProblems(array $rows, bool $gaps): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $from = substr((string) $row->effective_from, 0, 10);
            $to = $row->effective_to ? substr((string) $row->effective_to, 0, 10) : null;
            if ($to !== null && $to < $from) {
                $out[] = ['type' => 'invalid_range', 'at' => $from];
            }
            $next = $rows[$i + 1] ?? null;
            if (! $next) {
                continue;
            }
            $nextFrom = substr((string) $next->effective_from, 0, 10);
            if ($to === null || $to >= $nextFrom) {
                $out[] = ['type' => 'overlap', 'at' => $nextFrom];
            } elseif ($gaps && CarbonImmutable::parse($to)->addDay()->toDateString() !== $nextFrom) {
                $out[] = ['type' => 'gap', 'at' => $to];
            }
        }

        return $out;
    }
}
