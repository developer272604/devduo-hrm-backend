<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Engine\Simulator;
use App\Domain\Attendance\Models\AttendancePolicyVersion;
use App\Domain\People\Models\Employee;
use Illuminate\Validation\ValidationException;

/** Policy simulator + impact preview. Reads only (uses InputBuilder against current raw data); never writes. */
final class PolicySimulation
{
    public const MAX_EMPLOYEES = 500;

    public const MAX_DAYS = 31;

    public const CONFIRM_ABOVE = 100;

    public function __construct(private readonly InputBuilder $builder, private readonly ScopeExpander $scopes) {}

    public function simulate(array $data): array
    {
        $override = $data['rules'] ?? [];
        if (! empty($data['version_id'])) {
            $v = AttendancePolicyVersion::query()->find($data['version_id']) ?? throw ValidationException::withMessages(['version_id' => 'We could not find that policy version.']);
            $override = $v->rules ?? [];
        }
        if (! is_array($override) || $override === []) {
            throw ValidationException::withMessages(['rules' => 'Provide rules to test, or a draft version.']);
        }
        $scope = $this->scopes->normalize($data['scope'] + ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);
        if (\Carbon\CarbonImmutable::parse($scope['from'])->diffInDays($scope['to']) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Simulate at most '.self::MAX_DAYS.' days at a time.']);
        }
        $sample = min(self::MAX_EMPLOYEES, max(1, (int) ($data['sample_size'] ?? self::MAX_EMPLOYEES)));
        $comparisons = [];
        $examples = [];
        $employees = 0;
        foreach ($this->scopes->expand($scope) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                if ($employees++ >= $sample) {
                    break 2;
                }
                $employee = Employee::query()->find($employeeId);
                foreach ($dates as $date) {
                    $built = $this->builder->build($employee, $date);
                    $c = Simulator::compare($built->input, $override);
                    $comparisons[] = $c;
                    if ($c['changed'] && count($examples) < 20) {
                        $examples[] = ['employee_id' => $employeeId, 'employee_uuid' => $employee->uuid, 'date' => $date, 'differences' => $c['differences']];
                    }
                }
            }
        }
        $summary = Simulator::summarize($comparisons);

        return ['summary' => ['records' => $summary['total'], 'changed' => $summary['changed'], 'unchanged' => $summary['unchanged'], 'by_field' => $summary['by_field'], 'by_transition' => $summary['status_transitions'],
            'messages' => $summary['messages'], 'employees' => min($employees, $sample)], 'examples' => $examples, 'writes' => false];
    }

    public function impact(array $scope): array
    {
        $c = $this->scopes->count($scope);

        return $c + ['requires_confirmation' => $c['employee_days'] > self::CONFIRM_ABOVE,
            'message' => 'This change will recalculate attendance for '.$c['employees'].' employee'.($c['employees'] === 1 ? '' : 's').' across '.$c['dates'].' date'.($c['dates'] === 1 ? '' : 's').'.'];
    }
}
