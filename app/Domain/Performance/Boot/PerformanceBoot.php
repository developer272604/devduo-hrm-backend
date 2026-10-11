<?php

namespace App\Domain\Performance\Boot;

use App\Domain\Insights\ActionCenter;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Performance\Contracts\PerformanceOutcomeProvider;
use App\Domain\Performance\EloquentOutcomeProvider;
use App\Domain\Performance\Operations\{PerformanceEmployee360Provider, PerformanceInsightProvider, PerformanceQualityRules};
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Contracts\Foundation\Application;

/** Phase 8 wiring: outcome contract binding (consumed by Phase 5 compensation / Phase 7 promotions), Action Center insights, Employee 360 section, data-quality rules. Lazy: no DB access at boot. */
final class PerformanceBoot
{
    public static function register(Application $app): void
    {
        $app->bindIf(PerformanceOutcomeProvider::class, EloquentOutcomeProvider::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(ActionCenter::class)->register(PerformanceInsightProvider::class);
        $app->make(Employee360::class)->register(PerformanceEmployee360Provider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach (PerformanceQualityRules::all() as $rule) $engine->register($rule);
    }
}
