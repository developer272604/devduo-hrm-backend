<?php

namespace App\Domain\Attendance\Scheduling;

/**
 * Placeholder contract: "approved unavailability" (approved leave, long absence, ...) that should block or flag
 * a rostered shift. The Leave module (Phase 4) rebinds this; Phase 3 ships the null implementation.
 */
interface UnavailabilityProvider
{
    /**
     * @param  array<int,int>  $employeeIds
     * @return array<string,string> "employeeId|Y-m-d" => friendly reason (e.g. "Approved leave")
     */
    public function unavailable(array $employeeIds, string $from, string $to): array;
}
