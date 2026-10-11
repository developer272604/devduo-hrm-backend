<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Engine\CalculationInput;
use App\Domain\Attendance\Engine\DaySchedule;

/** The result of InputBuilder::build: the calculator input plus everything the persister/trace needs. */
final class BuiltInput
{
    /**
     * @param  array<string,mixed>|null  $policy  PolicyResolver match (policy, version, assignment) or null
     * @param  array<int,array<string,mixed>>  $considered  assignments considered while resolving the policy
     * @param  array<string,mixed>  $snapshot  stored in the trace as `inputs`
     * @param  array<string,mixed>|null  $statusOverride  adjustment applied after calculation
     * @param  array<int,string>  $issues  missing_policy | missing_shift
     */
    public function __construct(
        public readonly CalculationInput $input,
        public readonly DaySchedule $schedule,
        public readonly ?array $policy,
        public readonly array $considered,
        public readonly array $snapshot,
        public readonly string $hash,
        public readonly ?array $statusOverride,
        public readonly array $issues,
    ) {}
}
