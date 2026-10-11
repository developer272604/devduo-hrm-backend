<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Bulk\BulkAttendanceActions;
use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Locks\DatabaseLockGuard;
use App\Domain\Attendance\Processing\BulkActions;
use App\Domain\Attendance\Processing\RecalculationService;
use Illuminate\Contracts\Foundation\Application;

final class ProcessingBoot
{
    public static function register(Application $app): void
    {
        $app->bind(AttendanceRecalculator::class, RecalculationService::class);
        $app->bind(LockGuard::class, DatabaseLockGuard::class);
    }

    public static function boot(Application $app): void
    {
        foreach (['recalculate', 'lock', 'unlock'] as $key) {
            BulkAttendanceActions::register(new BulkActions($key));
        }
    }
}
