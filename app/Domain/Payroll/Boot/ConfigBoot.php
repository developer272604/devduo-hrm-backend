<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\Payroll\Compensation\CompensationResolverImpl;
use App\Domain\Payroll\Contracts\{CompensationResolver, PackResolver};
use App\Domain\Payroll\CountryPacks\{DeclarativePack, PackService};
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Illuminate\Contracts\Foundation\Application;

/** Workstream A bindings: CompensationResolver, PackResolver and the generic "declarative" statutory interpreter. */
final class ConfigBoot
{
    public static function register(Application $app): void
    {
        $app->bind(CompensationResolver::class, CompensationResolverImpl::class);
        $app->bind(PackResolver::class, PackService::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(StatutoryPackRegistry::class)->register(DeclarativePack::HANDLER, DeclarativePack::class);
    }
}
