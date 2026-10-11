<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\Payroll\Calculation\FormulaEngine;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Contracts\ResultStore as ResultStoreContract;
use App\Domain\Payroll\Integration\AttendancePayrollInputProvider;
use App\Domain\Payroll\Integration\LeavePayrollInputProvider;
use App\Domain\Payroll\Integration\PayrollInputRegistry;
use App\Domain\Payroll\Payments\GenericCsvPaymentFormat;
use App\Domain\Payroll\Results\ResultStore;
use App\Domain\Payroll\Support\AccountingMapperRegistry;
use App\Domain\Payroll\Support\PaymentFormatRegistry;
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Payroll foundation bindings (auto-loaded by ModulesServiceProvider from app/Domain/Payroll/Boot/*Boot.php).
 *
 * WORKSTREAM RULE: never edit this file to add your own bindings or registrations. Create your own `<Name>Boot.php` in this directory
 * (ConfigBoot = A, RunBoot = B, OutputsBoot = C, OperationsBoot = D) with static register()/boot(); every *Boot.php here is discovered
 * automatically (alphabetical order). Registries are singletons, so registering from any Boot works:
 *
 *   StatutoryPackRegistry     ->register('declarative', DeclarativePack::class)       (A)
 *   PayrollInputRegistry      ->register('variable_pay', VariablePayProvider::class)  (B; attendance and leave are registered here)
 *   PaymentFormatRegistry     ->register('some_bank', SomeBankFormat::class)          (C; generic_csv is registered here)
 *   AccountingMapperRegistry  ->register('journal_csv', JournalCsvMapper::class)      (C)
 *   FormulaEvaluator          bound here to FormulaEngine (A may rebind with an extended grammar in its own Boot)
 *   Contracts\ResultStore     bound here: the only writer of payroll results
 */
final class FoundationBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(StatutoryPackRegistry::class);
        $app->singleton(PaymentFormatRegistry::class);
        $app->singleton(AccountingMapperRegistry::class);
        $app->singleton(PayrollInputRegistry::class);
        $app->bind(FormulaEvaluator::class, FormulaEngine::class);
        $app->singleton(ResultStoreContract::class, ResultStore::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(PayrollInputRegistry::class)->register('attendance', AttendancePayrollInputProvider::class);
        $app->make(PayrollInputRegistry::class)->register('leave', LeavePayrollInputProvider::class);
        $app->make(PaymentFormatRegistry::class)->register(GenericCsvPaymentFormat::CODE, GenericCsvPaymentFormat::class);
    }
}
