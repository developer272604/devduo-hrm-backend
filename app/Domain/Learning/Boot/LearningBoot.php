<?php

namespace App\Domain\Learning\Boot;

use App\Domain\Insights\ActionCenter;
use App\Domain\Learning\Operations\{LearningEmployee360Provider, LearningInsightProvider, LearningQualityRules};
use App\Domain\People\Employee360\Employee360;
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Contracts\Foundation\Application;

/** Phase 9 wiring: Action Center insights, Employee 360 section and data-quality rules. Lazy: no DB access at boot. */
final class LearningBoot
{
    public static function boot(Application $app): void
    {
        $app->make(ActionCenter::class)->register(LearningInsightProvider::class);
        $app->make(Employee360::class)->register(LearningEmployee360Provider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach (LearningQualityRules::all() as $rule) $engine->register($rule);
    }
}
