<?php
namespace App\Domain\Operations\Boot;
use App\Domain\Assets\AssetClearanceProvider;
use App\Domain\Expenses\ExpenseClearanceProvider;
use App\Domain\Insights\ActionCenter;
use App\Domain\Lifecycle\Contracts\ClearanceRegistry;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Operations\{OperationsEmployee360Provider, OperationsInsightProvider, OperationsQualityRules};
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Contracts\Foundation\Application;
final class OperationsModuleBoot {
    public static function boot(Application $app): void {
        $app->make(ClearanceRegistry::class)->register(new AssetClearanceProvider);
        $app->make(ClearanceRegistry::class)->register(new ExpenseClearanceProvider);
        $app->make(Employee360::class)->register(OperationsEmployee360Provider::class);
        $app->make(ActionCenter::class)->register(OperationsInsightProvider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach (OperationsQualityRules::all() as $rule) $engine->register($rule);
    }
}
