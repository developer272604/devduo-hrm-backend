<?php

namespace App\Console\Commands;

use App\Domain\People\Boot\OperationsBoot;
use App\Domain\Security\LegalHoldService;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeLeaveExports extends Command
{
    protected $signature = 'leave:purge-exports';

    protected $description = 'Delete expired leave export files';

    public function handle(TenantRunner $runner): int
    {
        $count = 0;
        $rows = DB::table('leave_exports')->whereNotNull('path')->where('expires_at', '<', now())->get(['id', 'tenant_id', 'path'])->groupBy('tenant_id');
        foreach ($rows as $tenantId => $tenantRows) {
            $count += $runner->run((int) $tenantId, function () use ($tenantRows) {
                if (LegalHoldService::held('leave')) {
                    return 0;
                }
                $n = 0;
                foreach ($tenantRows as $row) {
                    Storage::disk(OperationsBoot::EXPORTS)->delete($row->path);
                    DB::table('leave_exports')->where('id', $row->id)->update(['path' => null, 'status' => 'expired', 'updated_at' => now()]);
                    $n++;
                }

                return $n;
            });
        }
        $this->info("Purged $count expired export(s).");

        return self::SUCCESS;
    }
}
