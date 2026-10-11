<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Findings are upserted, auto-resolved and reopened by the engine. Schedule daily. */
class ScanQuality extends Command
{
    protected $signature = 'people:scan-quality {--tenant= : Only this tenant id}';

    protected $description = 'Run the people data-quality scan for every tenant';

    public function handle(TenantRunner $runner): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $result = $runner->run((int) $id, fn () => app(\App\Domain\Quality\DataQualityEngine::class)->scan((int) $id));
                $this->line('tenant '.$id.': '.json_encode($result));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
