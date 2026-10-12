<?php

namespace App\Console\Commands;

use App\Domain\Payroll\Payments\PaymentService;
use App\Domain\Security\LegalHoldService;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgePayrollExports extends Command
{
    protected $signature = 'payroll:purge-exports';

    protected $description = 'Delete expired payroll export and bank payment files';

    public function handle(TenantRunner $runner): int
    {
        $count = 0;
        $tenantIds = DB::table('payroll_exports')->whereNotNull('file_path')->where('expires_at', '<', now())->pluck('tenant_id')
            ->merge(DB::table('bank_file_exports')->whereNotNull('file_path')->where('expires_at', '<', now())->pluck('tenant_id'))
            ->unique();

        foreach ($tenantIds as $tenantId) {
            $count += $runner->run((int) $tenantId, function () {
                if (LegalHoldService::held('payroll')) {
                    return 0;
                }

                return $this->purgePayrollReports() + $this->purgeBankFiles();
            });
        }

        $this->info("Purged $count expired payroll file(s).");

        return self::SUCCESS;
    }

    private function purgePayrollReports(): int
    {
        $count = 0;
        $rows = DB::table('payroll_exports')->whereNotNull('file_path')->where('expires_at', '<', now())->get(['id', 'file_path']);
        foreach ($rows as $row) {
            Storage::disk(PaymentService::DISK)->delete($row->file_path);
            DB::table('payroll_exports')->where('id', $row->id)->update(['file_path' => null, 'status' => 'expired', 'updated_at' => now()]);
            $count++;
        }

        return $count;
    }

    private function purgeBankFiles(): int
    {
        $count = 0;
        $rows = DB::table('bank_file_exports')->whereNotNull('file_path')->where('expires_at', '<', now())->get(['id', 'file_path']);
        foreach ($rows as $row) {
            Storage::disk(PaymentService::DISK)->delete($row->file_path);
            DB::table('bank_file_exports')->where('id', $row->id)->update(['file_path' => null, 'status' => 'expired', 'updated_at' => now()]);
            $count++;
        }

        return $count;
    }
}
