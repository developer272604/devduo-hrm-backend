<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Scheduling\DatabaseScheduleResolver;
use App\Domain\Attendance\Scheduling\NullUnavailabilityProvider;
use App\Domain\Attendance\Scheduling\UnavailabilityProvider;
use Illuminate\Contracts\Foundation\Application;

/** Scheduling bindings: the schedule resolver contract and the (placeholder) approved-unavailability provider. */
final class ScheduleBoot
{
    public static function register(Application $app): void
    {
        $app->bind(ScheduleResolver::class, DatabaseScheduleResolver::class);
        $app->bindIf(UnavailabilityProvider::class, NullUnavailabilityProvider::class);
    }
}
