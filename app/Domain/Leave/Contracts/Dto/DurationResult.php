<?php

namespace App\Domain\Leave\Contracts\Dto;

/** Output of DurationCalculator. `explanation` is an ordered list of plain-language steps; `warnings` never block by themselves. */
final class DurationResult
{
    /**
     * @param  array<int,DurationSegment>  $segments
     * @param  array<int,string>  $explanation
     * @param  array<int,array{kind:string,severity:string,message:string,detail?:array<string,mixed>}>  $warnings
     * @param  array<int,string>  $errors  reasons the request cannot be made at all (below minimum, outside employment ...)
     */
    public function __construct(
        public readonly array $segments,
        public readonly string $chargedUnits,
        public readonly string $calendarUnits,
        public readonly array $explanation,
        public readonly array $warnings = [],
        public readonly ?int $policyVersionId = null,
        public readonly bool $valid = true,
        public readonly array $errors = [],
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'charged_units' => $this->chargedUnits,
            'calendar_units' => $this->calendarUnits,
            'policy_version_id' => $this->policyVersionId,
            'segments' => array_map(fn (DurationSegment $s) => get_object_vars($s), $this->segments),
            'explanation' => $this->explanation,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
