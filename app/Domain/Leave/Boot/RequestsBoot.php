<?php

namespace App\Domain\Leave\Boot;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Scheduling\UnavailabilityProvider;
use App\Domain\Leave\Contracts\DurationCalculator;
use App\Domain\Leave\Contracts\LeaveApproverResolver;
use App\Domain\Leave\Duration\DurationEngine;
use App\Domain\Leave\Integration\LedgerLeaveDataProvider;
use App\Domain\Leave\Integration\LeaveUnavailabilityProvider;
use App\Domain\Leave\Requests\LeaveApprovers;
use Illuminate\Contracts\Foundation\Application;

/**
 * Workstream C bindings: duration engine, approver routing, and the REAL Attendance integration.
 * Boot hooks run in class-name order, so App\Domain\Leave\Boot\* runs after Attendance\Boot\LeaveBoot / ScheduleBoot and wins.
 */
final class RequestsBoot
{
    public const DISK = 'leave_private';

    public static function register(Application $app): void
    {
        $app->bind(DurationCalculator::class, DurationEngine::class);
        $app->bind(LeaveApproverResolver::class, LeaveApprovers::class);
        $app->bind(LeaveDataProvider::class, LedgerLeaveDataProvider::class);
        $app->bind(UnavailabilityProvider::class, LeaveUnavailabilityProvider::class);
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.self::DISK)) {
            $config->set('filesystems.disks.'.self::DISK, ['driver' => 'local', 'root' => storage_path('app/private/leave'), 'visibility' => 'private', 'throw' => true, 'report' => false]);
        }
        if ($app->runningInConsole()) {
            \Illuminate\Console\Application::starting(function ($artisan) {
                $artisan->resolve(\App\Domain\Leave\Requests\Console\LeaveMaintenanceCommand::class);
            });
            $app->booted(function () use ($app) {
                $app->make(\Illuminate\Console\Scheduling\Schedule::class)->command('leave:maintain-requests')->hourly()->withoutOverlapping();
            });
        }
    }
}
