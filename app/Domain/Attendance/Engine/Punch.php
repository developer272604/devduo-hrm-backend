<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/**
 * One time event as seen by the engine: a real raw punch, or a virtual one produced by an approved
 * regularization or manual adjustment (origin tells which, so the trace can say where each time came from).
 */
final class Punch
{
    public function __construct(
        public readonly string $id,                 // "event:123" | "regularization:7" | "adjustment:9"
        public readonly CarbonImmutable $at,        // UTC instant
        public readonly string $direction,          // IN | OUT | UNKNOWN
        public readonly string $source = 'web',
        public readonly string $origin = 'raw',     // raw | regularization | adjustment
        public readonly ?string $geofenceStatus = null,
        public readonly ?string $verificationStatus = null,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'at' => $this->at->utc()->toIso8601String(), 'direction' => $this->direction, 'source' => $this->source, 'origin' => $this->origin,
            'geofence' => $this->geofenceStatus, 'verification' => $this->verificationStatus];
    }
}
