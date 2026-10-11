<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\LeaveDataProvider;

/** Phase 3 default: no leave module yet, so nothing is covered. Phase 4 rebinds LeaveDataProvider to a real implementation. */
final class NullLeaveDataProvider implements LeaveDataProvider
{
    public function coverage(int $employeeId, string $date): ?array
    {
        return null;
    }

    public function coverageForRange(array $employeeIds, string $from, string $to): array
    {
        return [];
    }
}
