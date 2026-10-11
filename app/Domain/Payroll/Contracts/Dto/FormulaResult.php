<?php

namespace App\Domain\Payroll\Contracts\Dto;

/** Value of one formula evaluation plus the trace that is stored with the payroll result. */
final class FormulaResult
{
    /** @param array<string,string> $usedVariables */
    public function __construct(
        public readonly string $value,
        public readonly string $expression,
        public readonly array $usedVariables,
        public readonly int $scale,
    ) {}

    /** @return array{expression:string,variables:array<string,string>,scale:int,value:string} */
    public function trace(): array
    {
        return ['expression' => $this->expression, 'variables' => $this->usedVariables, 'scale' => $this->scale, 'value' => $this->value];
    }
}
