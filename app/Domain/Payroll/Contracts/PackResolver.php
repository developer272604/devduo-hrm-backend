<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Models\CountryPackVersion;

/**
 * Resolves which PUBLISHED country pack version governs a payroll group on a date (effective-dated; the run stores the id it used so history
 * is reproducible). Owner: workstream A (binds it in ConfigBoot). A pack that is not enabled, or a version that is not published / not
 * verified when the tenant requires verification, resolves to null so readiness can report "statutory configuration missing".
 */
interface PackResolver
{
    public function forGroup(int $payrollGroupId, string $date): ?CountryPackVersion;

    /** The version that was in force on $date for the pack (used by corrections and retro to price a past period with ITS rules). */
    public function version(int $packId, string $date): ?CountryPackVersion;
}
