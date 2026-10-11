<?php

namespace App\Domain\Leave\Boot;

use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\AccrualRunService;
use App\Domain\Leave\Accrual\BalanceReadService;
use App\Domain\Leave\Accrual\EntitlementCalculator;
use App\Domain\Leave\Accrual\PeriodResolver;
use App\Domain\Leave\Adjustments\AdjustmentService;
use App\Domain\Leave\CarryForward\CarryForwardService;
use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Expiry\ConsumptionPlanner;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Imports\ImportService;
use Illuminate\Contracts\Foundation\Application;

/**
 * Workstream B bindings: the entitlement / accrual engine and the ledger-operation services (accounts, accrual runs,
 * carry-forward, expiry, adjustments, imports). Policy data is read lazily through LeavePolicyResolver (bound by workstream A),
 * so nothing here touches the database at boot.
 */
final class AccrualBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(PeriodResolver::class);
        $app->bind(EntitlementEngine::class, EntitlementCalculator::class);
        $app->bind(EntitlementCalculator::class);
        foreach ([AccountService::class, AccrualRunService::class, BalanceReadService::class, AdjustmentService::class, CarryForwardService::class, ExpiryService::class, ConsumptionPlanner::class, ImportService::class] as $service) {
            $app->bind($service);
        }
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.ImportService::DISK)) {
            $config->set('filesystems.disks.'.ImportService::DISK, ['driver' => 'local', 'root' => storage_path('app/private/leave'), 'visibility' => 'private', 'throw' => true, 'report' => false]);
        }
    }
}
