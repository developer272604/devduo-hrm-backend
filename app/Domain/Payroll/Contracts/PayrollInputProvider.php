<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Contracts\Dto\PayrollInputBatch;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;

/**
 * A source of standardized payroll inputs (attendance, leave, ...). Implementations CONSUME the finalized outputs of the owning
 * module (Phase 3 payroll-output, Phase 4 leave payroll contract) and must not recompute attendance or leave. They are read-only,
 * side-effect free, and return decimal-string quantities with provenance. Register in a Boot class:
 *   $app->make(PayrollInputRegistry::class)->register(MyProvider::class);
 */
interface PayrollInputProvider
{
    /** Stable source key, matches PayrollStates::INPUT_SOURCES (attendance, leave, overtime, ...). */
    public function source(): string;

    public function collect(PayrollInputQuery $query): PayrollInputBatch;
}
