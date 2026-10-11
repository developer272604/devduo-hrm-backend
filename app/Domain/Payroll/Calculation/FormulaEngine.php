<?php

namespace App\Domain\Payroll\Calculation;

use App\Domain\Payroll\Contracts\Dto\FormulaResult;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use InvalidArgumentException;

/**
 * Default FormulaEvaluator over the bounded recursive-descent parser in Formula (no eval, no floats, whitelisted functions).
 * Adds variable discovery, static validation and a trace. Workstream A extends the grammar inside Formula (functions, comparisons, IF)
 * and keeps this contract stable.
 */
final class FormulaEngine implements FormulaEvaluator
{
    public function evaluate(string $expression, array $variables = [], int $scale = 6): FormulaResult
    {
        $used = [];
        foreach ($this->references($expression) as $name) {
            if (array_key_exists($name, $variables)) {
                $used[$name] = $variables[$name];
            }
        }
        $value = (new Formula)->calculate($expression, $used, $scale);

        return new FormulaResult($value, trim($expression), $used, $scale);
    }

    public function validate(string $expression, array $allowedVariables): array
    {
        $problems = [];
        foreach ($this->references($expression) as $name) {
            if (! in_array($name, $allowedVariables, true)) {
                $problems[] = 'Unknown variable "'.$name.'". Allowed: '.($allowedVariables ? implode(', ', $allowedVariables) : 'none').'.';
            }
        }
        if ($problems !== []) {
            return $problems;
        }
        try {
            (new Formula)->calculate($expression, array_fill_keys($allowedVariables, '1'), 6);
        } catch (InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    public function references(string $expression): array
    {
        preg_match_all('/(?<![A-Z0-9_])[A-Z][A-Z0-9_]*/', $expression, $matches);

        return array_values(array_unique(array_diff($matches[0], Formula::FUNCTIONS, Formula::KEYWORDS)));
    }
}
