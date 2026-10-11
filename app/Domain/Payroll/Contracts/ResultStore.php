<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Results\ResultDraft;

/**
 * The ONLY writer of payroll results (the payroll "ledger"). Contract:
 *  - Results and their components are append-only. A recalculation or correction is a NEW version; the previous version is marked
 *    superseded (is_current=false, superseded_by_id) but its figures are never touched.
 *  - record() is idempotent: recording an identical draft (same checksum) for the same run employee returns the current result.
 *  - Totals are derived from the components with decimal-safe arithmetic; callers never supply totals.
 *  - A locked / approved run refuses new versions unless the caller declares a controlled correction.
 *  - Every result carries a checksum over inputs, components and rule versions; verify() proves historical reproducibility.
 */
interface ResultStore
{
    public function record(ResultDraft $draft): PayrollCalculationResult;

    public function current(int $runEmployeeId): ?PayrollCalculationResult;

    /** Recomputes the checksum from the stored rows. true = untouched since it was recorded. */
    public function verify(PayrollCalculationResult $result): bool;
}
