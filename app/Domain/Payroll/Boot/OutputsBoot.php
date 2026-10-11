<?php

namespace App\Domain\Payroll\Boot;

use App\Domain\Payroll\Accounting\JournalCsvMapper;
use App\Domain\Payroll\Payments\PaymentService;
use App\Domain\Payroll\Support\AccountingMapperRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Workstream C wiring: private disk for payroll files (bank files, journals, tax proofs) and the neutral journal mapper.
 * Extra PaymentFormat / AccountingMapper adapters register here via the foundation registries.
 */
final class OutputsBoot
{
    public static function register(Application $app): void {}

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.PaymentService::DISK)) {
            $config->set('filesystems.disks.'.PaymentService::DISK, ['driver' => 'local', 'root' => storage_path('app/private/payroll'), 'visibility' => 'private', 'throw' => true, 'report' => false]);
        }
        $app->make(AccountingMapperRegistry::class)->register(JournalCsvMapper::CODE, JournalCsvMapper::class);
    }
}
