<?php

namespace App\Domain\Attendance\Services;

use Carbon\CarbonImmutable;

/** Attendance stores instants as UTC wall-clock strings; the app timezone is NOT UTC, so always go through here. */
final class Utc
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function str(?CarbonImmutable $at = null): string
    {
        return ($at ?? self::now())->utc()->format('Y-m-d H:i:s');
    }

    public static function parse(?string $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /** ISO-8601 with Z for API output. */
    public static function iso(?string $value): ?string
    {
        return self::parse($value)?->format('Y-m-d\TH:i:s\Z');
    }
}
