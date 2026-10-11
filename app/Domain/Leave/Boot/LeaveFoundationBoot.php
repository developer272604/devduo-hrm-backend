<?php

namespace App\Domain\Leave\Boot;

use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Locks\DatabaseLeaveLockGuard;
use Illuminate\Contracts\Foundation\Application;

/**
 * Leave foundation bindings (auto-loaded by ModulesServiceProvider from app/Domain/Leave/Boot/*Boot.php).
 *
 * WORKSTREAM RULE: never edit this file to add your own bindings. Create your own `<Name>Boot.php` in this directory
 * (e.g. PolicyBoot, RequestsBoot, OperationsBoot) with static register()/boot(); every *Boot.php here is discovered automatically.
 *
 * Contracts and who binds them (all bound with `bind`, not bindIf, in the implementer's own Boot):
 *   App\Domain\Leave\Contracts\LeavePolicyResolver   workstream A
 *   App\Domain\Leave\Contracts\EntitlementEngine     workstream B
 *   App\Domain\Leave\Contracts\DurationCalculator    workstream C
 *   App\Domain\Leave\Contracts\LeaveApproverResolver workstream C (delegates to Attendance ApproverResolver)
 *   App\Domain\Leave\Contracts\LeaveLockGuard        bound here (workstream D may rebind)
 *
 * LeaveDataProvider (Attendance\Contracts\LeaveDataProvider) stays bound to NullLeaveDataProvider by Attendance\Boot\LeaveBoot
 * (bindIf) until workstream C adds Leave\Integration\LedgerLeaveDataProvider and rebinds it in its own Boot class with
 * `$app->bind(LeaveDataProvider::class, LedgerLeaveDataProvider::class)`. Hooks run in class-name order, so
 * App\Domain\Leave\Boot\* always runs after App\Domain\Attendance\Boot\LeaveBoot and wins. The real provider reads approved
 * leave_request_segments (status active, request approved) only, must be batch-efficient for coverageForRange, and must never
 * expose reasons/medical detail (coverage returns minutes, portion, opaque reference, paid flag).
 */
final class LeaveFoundationBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(BalanceService::class);
        $app->singleton(LedgerService::class);
        $app->bind(LeaveLockGuard::class, DatabaseLeaveLockGuard::class);
    }
}
