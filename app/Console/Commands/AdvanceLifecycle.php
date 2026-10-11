<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent. Schedule hourly (tenant timezones differ). */
class AdvanceLifecycle extends Command
{
    protected $signature = 'people:advance-lifecycle {--tenant= : Only this tenant id}';

    protected $description = 'Activate preboarding employees whose joining date arrived and separate those past their last working day';

    public function handle(TenantRunner $runner): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $result = $runner->run((int) $id, fn () => app(\App\Domain\People\Insights\LifecycleJobs::class)->advanceLifecycle());
                $this->line('tenant '.$id.': '.json_encode($result));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
