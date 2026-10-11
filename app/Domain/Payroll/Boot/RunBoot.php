<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Engine\PayrollCalculator;
use Illuminate\Contracts\Foundation\Application;

/** Workstream B bindings: the pure EmployeeCalculator (also used by simulators / previews of workstream D). */
final class RunBoot
{
    public static function register(Application $app): void
    {
        $app->bind(EmployeeCalculator::class, PayrollCalculator::class);
    }

    public static function boot(Application $app): void {}
}
