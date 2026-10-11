<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/**
 * Output of the calculator. `steps` is the ordered rule trace (one entry per decision, with the numbers used),
 * `explanation` a structured, human-readable breakdown generated ONLY from those deterministic steps.
 */
final class CalculationResult
{
    /**
     * @param  array<int,array{type:string,severity:string,rule:string,details:array,title?:string}>  $exceptions
     * @param  array<int,array{in:string,out:?string,minutes:int}>  $pairs  paired sessions (ISO UTC)
     * @param  array<int,array<string,mixed>>  $steps
     * @param  array<string,mixed>  $explanation  {headline, status, lines:[{label,value,rule?}], facts:[...], actions:[...]}
     */
    public function __construct(
        public readonly string $status,
        public readonly string $confidence,                 // confirmed | review_required | pending
        public readonly ?CarbonImmutable $firstIn,
        public readonly ?CarbonImmutable $lastOut,
        public readonly int $requiredMinutes,
        public readonly int $grossMinutes,
        public readonly int $breakMinutes,
        public readonly int $netMinutes,
        public readonly int $lateMinutes,
        public readonly int $earlyExitMinutes,
        public readonly int $overtimeMinutes,
        public readonly array $overtimeBreakdown,          // {pre_shift, post_shift, weekly_off, holiday, rest_day}
        public readonly bool $missingPunch,
        public readonly string $workMode,                   // onsite | remote | on_duty
        public readonly array $exceptions,
        public readonly array $pairs,
        public readonly array $steps,
        public readonly array $explanation,
        public readonly array $punchIdsUsed,
        public readonly array $flags = [],                  // {late_counts_as_occurrence, early_counts_as_occurrence, half_day_by_late, ...}
    ) {}

    /** Stable array form, used for traces, comparisons and the simulator diff. */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'confidence' => $this->confidence, 'first_in' => $this->firstIn?->utc()->toIso8601String(), 'last_out' => $this->lastOut?->utc()->toIso8601String(),
            'required_minutes' => $this->requiredMinutes, 'gross_minutes' => $this->grossMinutes, 'break_minutes' => $this->breakMinutes, 'net_minutes' => $this->netMinutes,
            'late_minutes' => $this->lateMinutes, 'early_exit_minutes' => $this->earlyExitMinutes, 'overtime_minutes' => $this->overtimeMinutes, 'overtime' => $this->overtimeBreakdown,
            'missing_punch' => $this->missingPunch, 'work_mode' => $this->workMode, 'exceptions' => $this->exceptions, 'pairs' => $this->pairs, 'punch_ids' => $this->punchIdsUsed, 'flags' => $this->flags,
        ];
    }
}
