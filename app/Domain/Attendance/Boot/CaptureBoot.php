<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Capture\Devices\DeviceProviders;
use Illuminate\Contracts\Foundation\Application;

/** Capture workstream hook: private photo disk, device provider registry and scheduled capture jobs. Keep tiny and safe. */
final class CaptureBoot
{
    public const DISK = 'attendance_private';

    public static function register(Application $app): void
    {
        $app->singleton(DeviceProviders::class);
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.self::DISK)) {
            $config->set('filesystems.disks.'.self::DISK, [
                'driver' => 'local',
                'root' => storage_path('app/private/attendance'),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ]);
        }
        $app->make(DeviceProviders::class)->registerBuiltIns();
        $app->booted(function () use ($app) {
            if ($app->runningInConsole()) {
                $schedule = $app->make(\Illuminate\Console\Scheduling\Schedule::class);
                $schedule->command('attendance:sync-devices')->everyFiveMinutes()->withoutOverlapping();
                $schedule->command('attendance:purge-location-data')->dailyAt('02:30')->withoutOverlapping();
            }
        });
    }
}
