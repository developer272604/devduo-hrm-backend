<?php

namespace App\Domain\People\Records;

use App\Domain\Access\PeopleSettings;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;

/** Expiry classification shared by documents, identity records and work authorizations. */
final class Expiry
{
    public const DEFAULT_WINDOW_DAYS = 30;

    public static function windowDays(): int
    {
        return max(1, min(365, (int) PeopleSettings::get('document_expiry_days', self::DEFAULT_WINDOW_DAYS)));
    }

    /** @return 'expired'|'expiring'|'valid'|null */
    public static function status(?\DateTimeInterface $expiry, ?int $window = null): ?string
    {
        if (! $expiry) {
            return null;
        }
        $today = CarbonImmutable::parse(TenantClock::today());
        $date = CarbonImmutable::parse($expiry->format('Y-m-d'));
        if ($date->lt($today)) {
            return 'expired';
        }

        return $date->lte($today->addDays($window ?? self::windowDays())) ? 'expiring' : 'valid';
    }
}
