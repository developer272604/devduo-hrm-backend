<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\Insights\ActionCenter;
use App\Domain\Payroll\Operations\PayrollInsightProvider;
use App\Domain\Payroll\Quality\PayrollQualityRules;
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Contracts\Foundation\Application;

/** Workstream D wiring: Action Center insights and payroll data-quality rules (lazy, no database access at boot). Employee 360 is registered by PayrollBoot. */
final class OperationsBoot
{
    public static function boot(Application $app): void
    {
        if (class_exists(ActionCenter::class)) {
            $app->make(ActionCenter::class)->register(PayrollInsightProvider::class);
        }
        if (class_exists(DataQualityEngine::class)) {
            $engine = $app->make(DataQualityEngine::class);
            foreach (PayrollQualityRules::all() as $rule) {
                $engine->register($rule);
            }
        }
    }
}
