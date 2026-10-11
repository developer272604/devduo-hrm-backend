<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Operations\AttendanceEmployee360Provider;
use App\Domain\Attendance\Operations\AttendanceInsightProvider;
use App\Domain\Attendance\Operations\Quality\AttendanceQualityRules;
use App\Domain\Insights\ActionCenter;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

/** Registers the attendance insight provider, Employee 360 provider and data-quality rules. Lazy: never touches the database at boot. */
final class OperationsBoot
{
    public static function boot(Application $app): void
    {
        if (class_exists(ActionCenter::class)) {
            $app->make(ActionCenter::class)->register(AttendanceInsightProvider::class);
        }
        if (class_exists(Employee360::class)) {
            $app->make(Employee360::class)->register(AttendanceEmployee360Provider::class);
        }
        if (class_exists(DataQualityEngine::class)) {
            $engine = $app->make(DataQualityEngine::class);
            foreach (AttendanceQualityRules::all() as $rule) {
                $engine->register($rule);
            }
        }
        $app->booted(function () use ($app) {
            if ($app->runningInConsole()) {
                $app->make(Schedule::class)->command('attendance:purge-exports')->hourly();
            }
        });
    }
}
