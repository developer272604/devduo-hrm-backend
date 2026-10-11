<?php

namespace App\Domain\Leave\Boot;

use App\Domain\Events\DomainEventRecorded;
use App\Domain\Insights\ActionCenter;
use App\Domain\Leave\Operations\Bulk\LeaveBulkActions;
use App\Domain\Leave\Operations\Bulk\PrepareHandlingAction;
use App\Domain\Leave\Operations\LeaveEmployee360Provider;
use App\Domain\Leave\Operations\LeaveInsightProvider;
use App\Domain\Leave\Operations\Quality\LeaveQualityRules;
use App\Domain\Leave\Transfers\PeopleEventListener;
use App\Domain\People\Employee360\Employee360;
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;

/**
 * Workstream D wiring (Leave operations): bulk-action registry, Action Center insights, Employee 360 panel, data-quality rules,
 * People event listener (transfer / separation / rehire previews) and the hourly export purge. Lazy: never touches the database at boot.
 */
final class OperationsBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(LeaveBulkActions::class, function () {
            $registry = new LeaveBulkActions;
            $registry->register(new PrepareHandlingAction);

            return $registry;
        });
    }

    public static function boot(Application $app): void
    {
        if (class_exists(ActionCenter::class)) {
            $app->make(ActionCenter::class)->register(LeaveInsightProvider::class);
        }
        if (class_exists(Employee360::class)) {
            $app->make(Employee360::class)->register(LeaveEmployee360Provider::class);
        }
        if (class_exists(DataQualityEngine::class)) {
            $engine = $app->make(DataQualityEngine::class);
            foreach (LeaveQualityRules::all() as $rule) {
                $engine->register($rule);
            }
        }
        Event::listen(DomainEventRecorded::class, [PeopleEventListener::class, 'handle']);
        $app->booted(function () use ($app) {
            if ($app->runningInConsole()) {
                $app->make(Schedule::class)->command('leave:purge-exports')->hourly();
            }
        });
    }
}
