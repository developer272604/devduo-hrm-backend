<?php

namespace App\Domain\Payroll\Engine;

use RuntimeException;

/** An employee-level calculation failure with a stable code; the calculator converts it into draft errors (never a run failure). */
final class CalculationFailed extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
