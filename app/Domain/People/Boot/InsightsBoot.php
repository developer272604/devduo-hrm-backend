<?php

namespace App\Domain\People\Boot;

use App\Domain\Insights\ActionCenter;
use App\Domain\People\Employee360\Employee360;
use App\Domain\People\Employee360\PeopleEmployee360Provider;
use App\Domain\People\Insights\PeopleInsightProvider;
use App\Domain\Quality\DataQualityEngine;
use App\Domain\Quality\Rules\ConflictingEffectiveDatesRule;
use App\Domain\Quality\Rules\CountryRequiredInfoRule;
use App\Domain\Quality\Rules\DuplicateEmployeesRule;
use App\Domain\Quality\Rules\ExpiredDocumentsRule;
use App\Domain\Quality\Rules\InvalidContactInfoRule;
use App\Domain\Quality\Rules\InvalidEmploymentDatesRule;
use App\Domain\Quality\Rules\InvalidOrgReferenceRule;
use App\Domain\Quality\Rules\MissingManagerRule;
use App\Domain\Quality\Rules\MissingRequiredFieldsRule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Insights registries. Other modules (Attendance, ...) register in their own Boot class:
 *   $app->make(ActionCenter::class)->register(MyInsightProvider::class);
 *   $app->make(Employee360::class)->register(MyEmployee360Provider::class);
 *   $app->make(DataQualityEngine::class)->register(MyQualityRule::class);
 * Registrations are lazy (class names) so booting never touches the database.
 */
final class InsightsBoot
{
    public static function register(Application $app): void
    {
        foreach ([ActionCenter::class, Employee360::class, DataQualityEngine::class] as $registry) {
            $app->singleton($registry);
        }
    }

    public static function boot(Application $app): void
    {
        $app->make(ActionCenter::class)->register(PeopleInsightProvider::class);
        $app->make(Employee360::class)->register(PeopleEmployee360Provider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach ([MissingRequiredFieldsRule::class, InvalidOrgReferenceRule::class, DuplicateEmployeesRule::class, ExpiredDocumentsRule::class, ConflictingEffectiveDatesRule::class,
            MissingManagerRule::class, InvalidEmploymentDatesRule::class, CountryRequiredInfoRule::class, InvalidContactInfoRule::class] as $rule) {
            $engine->register($rule);
        }
        RateLimiter::for('people-remind', fn ($request) => Limit::perMinute(10)->by('people-remind:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('people-quality-scan', fn ($request) => Limit::perMinute(3)->by('people-quality-scan:'.($request->user()?->id ?? $request->ip())));
    }
}
