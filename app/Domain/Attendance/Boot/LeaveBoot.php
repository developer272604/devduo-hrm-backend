<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Services\NullLeaveDataProvider;
use Illuminate\Contracts\Foundation\Application;

/** Binds the default (null) leave provider; the Leave module (Phase 4) rebinds LeaveDataProvider. */
final class LeaveBoot
{
    public static function register(Application $app): void
    {
        $app->bindIf(LeaveDataProvider::class, NullLeaveDataProvider::class);
    }
}
