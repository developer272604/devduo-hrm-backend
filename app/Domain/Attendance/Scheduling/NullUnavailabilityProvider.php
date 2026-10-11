<?php

namespace App\Domain\Attendance\Scheduling;

final class NullUnavailabilityProvider implements UnavailabilityProvider
{
    public function unavailable(array $employeeIds, string $from, string $to): array
    {
        return [];
    }
}
