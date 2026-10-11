<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Contracts\Dto\FormulaResult;

/**
 * Safe payroll formula evaluation. Implementations MUST be a bounded parser over a whitelist of operators / functions:
 * no eval, no dynamic function names, no I/O, no floats. Variables are decimal strings. The result carries a trace
 * (expression, variables actually used, scale) so historical payroll can retain the formula trace.
 */
interface FormulaEvaluator
{
    /** @param array<string,string> $variables */
    public function evaluate(string $expression, array $variables = [], int $scale = 6): FormulaResult;

    /**
     * Static validation against the variable names a component may use. Returns user-facing problems (empty = valid).
     *
     * @param  array<int,string>  $allowedVariables
     * @return array<int,string>
     */
    public function validate(string $expression, array $allowedVariables): array;

    /** Variable names referenced by the expression (functions excluded), in first-use order. @return array<int,string> */
    public function references(string $expression): array;
}
