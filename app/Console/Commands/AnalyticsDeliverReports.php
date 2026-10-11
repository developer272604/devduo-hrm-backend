<?php

namespace App\Console\Commands;

use App\Domain\Analytics\ExportJobService;
use App\Domain\Analytics\ReportDelivery;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class AnalyticsDeliverReports extends Command
{
    protected $signature = 'analytics:deliver-reports {--tenant= : Only this tenant id}';

    protected $description = 'Deliver due scheduled analytics reports in-app (idempotent per schedule slot and recipient) and purge expired files';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $stats = $runner->run((int) $id, function () {
                    $stats = app(ReportDelivery::class)->runDue();
                    $stats['purged'] = app(ReportDelivery::class)->purgeExpired() + app(ExportJobService::class)->purgeExpired();

                    return $stats;
                });
                $this->line("tenant {$id}: schedules {$stats['processed']}, delivered {$stats['sent']}, skipped {$stats['skipped']}, purged {$stats['purged']}");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
