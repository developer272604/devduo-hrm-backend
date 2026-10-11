<?php

namespace App\Domain\Attendance\Capture\Devices;

use Carbon\CarbonImmutable;

/** Vendor-neutral punch produced by a provider. NormalizedPunch -> PunchService::record is the only ingestion path. */
final class NormalizedPunch
{
    public function __construct(
        public readonly string $deviceEmployeeId,
        public readonly CarbonImmutable $occurredAt,   // an absolute instant (timezone already resolved)
        public readonly string $direction = 'UNKNOWN', // IN | OUT | UNKNOWN
        public readonly ?string $externalRef = null,
        public readonly array $rawMetadata = [],
    ) {}

    public function toArray(): array
    {
        return ['device_employee_id' => $this->deviceEmployeeId, 'occurred_at' => $this->occurredAt->utc()->toIso8601String(), 'direction' => $this->direction, 'external_ref' => $this->externalRef];
    }

    public static function direction(mixed $value): string
    {
        $v = strtoupper(trim((string) $value));

        return match (true) {
            in_array($v, ['IN', 'I', 'CHECKIN', 'CHECK_IN', 'CHECK-IN', '0', 'ENTRY', 'ENTER'], true) => 'IN',
            in_array($v, ['OUT', 'O', 'CHECKOUT', 'CHECK_OUT', 'CHECK-OUT', '1', 'EXIT', 'LEAVE'], true) => 'OUT',
            default => 'UNKNOWN',
        };
    }
}
