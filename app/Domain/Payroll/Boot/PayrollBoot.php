<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\People\Employee360\Employee360;
use App\Domain\Payroll\Operations\PayrollEmployee360Provider;
use Illuminate\Contracts\Foundation\Application;

final class PayrollBoot
{
    public static function boot(Application $app): void
    {
        $app->make(Employee360::class)->register(PayrollEmployee360Provider::class);
    }
}
