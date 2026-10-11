<?php

namespace App\Domain\Intelligence\Boot;

use App\Domain\Intelligence\IntelligenceEmployee360Provider;
use App\Domain\Intelligence\ScenarioService;
use App\Domain\People\Employee360\Employee360;
use Illuminate\Contracts\Foundation\Application;

/** Phase 16 wiring (lazy, no DB access at boot). */
final class IntelligenceBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(ScenarioService::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(Employee360::class)->register(IntelligenceEmployee360Provider::class);
    }
}
