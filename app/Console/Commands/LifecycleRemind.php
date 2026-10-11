<?php

namespace App\Console\Commands;

use App\Domain\Lifecycle\LifecycleJobs;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent: reminded_at / escalated_at stamps guarantee each task is reminded and escalated once. Schedule daily. */
class LifecycleRemind extends Command
{
    protected $signature = 'lifecycle:remind {--tenant= : Only this tenant id}';

    protected $description = 'Remind owners of lifecycle tasks due soon and escalate overdue ones';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, fn () => app(LifecycleJobs::class)->remind())));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
