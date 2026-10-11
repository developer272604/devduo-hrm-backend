<?php
namespace App\Domain\Operations\Boot;
use App\Domain\Assets\AssetClearanceProvider;
use App\Domain\Expenses\ExpenseClearanceProvider;
use App\Domain\Lifecycle\Contracts\ClearanceRegistry;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Operations\OperationsEmployee360Provider;
use Illuminate\Contracts\Foundation\Application;
final class OperationsModuleBoot {
    public static function boot(Application $app): void {
        $app->make(ClearanceRegistry::class)->register(new AssetClearanceProvider);
        $app->make(ClearanceRegistry::class)->register(new ExpenseClearanceProvider);
        $app->make(Employee360::class)->register(OperationsEmployee360Provider::class);
    }
}
