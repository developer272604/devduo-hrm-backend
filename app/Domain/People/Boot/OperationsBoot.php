<?php

namespace App\Domain\People\Boot;

use App\Domain\People\Operations\BulkActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

/** Runtime wiring for people operations: private import/export disks, the bulk-action registry, export purge schedule. */
final class OperationsBoot
{
    public const IMPORTS = 'hrm_imports';

    public const EXPORTS = 'hrm_exports';

    public static function register(Application $app): void
    {
        $app->singleton(BulkActions::class, fn () => (new BulkActions)->registerDefaults());
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        foreach ([self::IMPORTS => 'imports', self::EXPORTS => 'exports'] as $disk => $folder) {
            if (! $config->has('filesystems.disks.'.$disk)) {
                $config->set('filesystems.disks.'.$disk, ['driver' => 'local', 'root' => storage_path('app/private/'.$folder), 'visibility' => 'private', 'throw' => true, 'report' => false]);
            }
        }
        $app->booted(function () use ($app) {
            if ($app->runningInConsole()) {
                $app->make(Schedule::class)->command('people:purge-exports')->hourly();
            }
        });
    }
}
