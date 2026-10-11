<?php

namespace App\Domain\People\Boot;

use Illuminate\Contracts\Foundation\Application;

/**
 * Runtime registration for the sensitive-records area: the private document disk.
 * The disk is local, has no public URL and is never served; files are streamed only after authorization.
 */
final class RecordsBoot
{
    public const DISK = 'hrm_private';

    public static function register(Application $app): void
    {
        // No container bindings are needed: services are plain classes resolved by the container.
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.self::DISK)) {
            $config->set('filesystems.disks.'.self::DISK, [
                'driver' => 'local',
                'root' => storage_path('app/private/hrm'),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ]);
        }
    }
}
