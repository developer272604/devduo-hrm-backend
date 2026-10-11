<?php

namespace App\Domain\Leave\Contracts;

/**
 * Computes what an employee is entitled to for a cycle period (tenure bands, proration for joiners/leavers/transfers,
 * probation, caps) and the accrual due for a window. Pure calculation: never writes the ledger (accrual runs do, through
 * LedgerService). Implemented by workstream B (Leave/Accrual/EntitlementCalculator).
 *
 * Return shape (both methods): `units` is a 4-dp decimal string; `lines` is the human-readable explanation shown by the
 * Leave Explainer / Entitlement Simulator; `skipped` is a reason string when nothing is due (not eligible, already accrued).
 */
interface EntitlementEngine
{
    /** @return array{units:string,lines:array<int,array{label:string,units:string,detail?:array<string,mixed>}>,skipped?:string|null} */
    public function entitlementFor(int $employeeId, int $leaveTypeId, string $periodStart, string $periodEnd): array;

    /** @return array{units:string,lines:array<int,array{label:string,units:string,detail?:array<string,mixed>}>,skipped?:string|null} */
    public function accrualFor(int $employeeId, int $leaveTypeId, string $from, string $to): array;
}
