<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;

/**
 * Push model: the device or its vendor gateway POSTs events to /api/device/v1/events.
 * Event shape: {device_employee_id|employee_id|user_id, timestamp|time|punched_at, direction|type|status, external_ref|event_id|id, ...extra kept in metadata}.
 * Timestamps with an explicit offset (ISO-8601) or epoch numbers are absolute; naive strings are read in the device timezone.
 */
class GenericApiProvider implements AttendanceDeviceProvider
{
    public function key(): string
    {
        return 'generic_api';
    }

    public function label(): string
    {
        return 'Generic API (push)';
    }

    public function pulls(): bool
    {
        return false;
    }

    public function configurationSchema(): array
    {
        return [];
    }

    public function testConnection(AttendanceDevice $device): array
    {
        return ['ok' => true, 'message' => $device->auth_token_hash ? 'This device pushes events to the device API. Waiting for the first event.' : 'Issue a device token so the device can authenticate.'];
    }

    public function fetchEvents(AttendanceDevice $device, ?CarbonImmutable $since): iterable
    {
        return [];
    }

    public function normalizePayload(array $raw, ?AttendanceDevice $device = null): NormalizedPunch
    {
        $id = $raw['device_employee_id'] ?? $raw['employee_id'] ?? $raw['user_id'] ?? $raw['badge'] ?? $raw['pin'] ?? null;
        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new \InvalidArgumentException('The event has no employee identifier (device_employee_id).');
        }
        $stamp = $raw['timestamp'] ?? $raw['time'] ?? $raw['punched_at'] ?? $raw['occurred_at'] ?? null;
        if (! is_scalar($stamp) || $stamp === '') {
            throw new \InvalidArgumentException('The event has no timestamp.');
        }
        $zone = $device?->timezone && TimeZones::isValid($device->timezone) ? $device->timezone : 'UTC';
        $ref = $raw['external_ref'] ?? $raw['event_id'] ?? $raw['id'] ?? null;

        return new NormalizedPunch(
            substr(trim((string) $id), 0, 80),
            self::parseInstant($stamp, $zone),
            NormalizedPunch::direction($raw['direction'] ?? $raw['type'] ?? $raw['status'] ?? null),
            is_scalar($ref) ? substr((string) $ref, 0, 120) : null,
            ['original_timestamp' => (string) $stamp, 'timezone_assumed' => self::hasOffset((string) $stamp) || is_numeric($stamp) ? null : $zone, 'vendor' => array_diff_key($raw, array_flip(['timestamp', 'time', 'punched_at', 'occurred_at']))],
        );
    }

    public static function hasOffset(string $stamp): bool
    {
        return (bool) preg_match('/\d{2}:\d{2}(:\d{2})?(\.\d+)?\s*(Z|[+-]\d{2}:?\d{2})$/i', trim($stamp));
    }

    /** Epoch numbers and offset-bearing ISO strings are absolute; naive strings are read in $zone (DST fold = first occurrence). */
    public static function parseInstant(mixed $stamp, string $zone): CarbonImmutable
    {
        try {
            if (is_int($stamp) || (is_string($stamp) && ctype_digit($stamp) && strlen($stamp) >= 9)) {
                $n = (int) $stamp;

                return CarbonImmutable::createFromTimestampUTC($n > 99999999999 ? intdiv($n, 1000) : $n);
            }
            $stamp = trim((string) $stamp);
            if (self::hasOffset($stamp)) {
                return CarbonImmutable::parse($stamp)->utc();
            }
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?(\.\d+)?$/', $stamp, $m)) {
                throw new \InvalidArgumentException('format');
            }

            return TimeZones::localToUtc($m[1], $m[2].(($m[3] ?? '') !== '' ? $m[3] : ':00'), $zone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('The timestamp "'.(is_scalar($stamp) ? $stamp : 'value').'" is not a valid date and time. Use ISO-8601, for example 2026-10-07T09:02:00+05:30.');
        }
    }
}
