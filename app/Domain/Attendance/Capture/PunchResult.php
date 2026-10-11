<?php

namespace App\Domain\Attendance\Capture;

use App\Domain\Attendance\Models\AttendanceEvent;

/** Outcome of PunchService::record(). `duplicate` means an existing punch was returned and nothing new was stored. */
final class PunchResult
{
    public function __construct(
        public readonly AttendanceEvent $event,
        public readonly bool $duplicate = false,
        public readonly ?string $reason = null,    // idempotency_key | external_ref | duplicate_window
        public readonly ?array $geofence = null,
    ) {}
}
