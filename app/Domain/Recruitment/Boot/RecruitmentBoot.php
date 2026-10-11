<?php

namespace App\Domain\Recruitment\Boot;

use App\Domain\Insights\ActionCenter;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Quality\DataQualityEngine;
use App\Domain\Recruitment\Contracts\{CandidateChannelAdapter, NullChannelAdapter};
use App\Domain\Recruitment\Operations\{RecruitmentEmployee360Provider, RecruitmentInsightProvider, RecruitmentQualityRules};
use Illuminate\Contracts\Foundation\Application;

/** Phase 6 wiring: neutral channel adapter, Employee 360 "recruitment source", Action Center insights, data-quality rules. Lazy: no DB access at boot. */
final class RecruitmentBoot
{
    public static function register(Application $app): void
    {
        $app->bindIf(CandidateChannelAdapter::class, NullChannelAdapter::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(ActionCenter::class)->register(RecruitmentInsightProvider::class);
        $app->make(Employee360::class)->register(RecruitmentEmployee360Provider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach (RecruitmentQualityRules::all() as $rule) $engine->register($rule);
    }
}
