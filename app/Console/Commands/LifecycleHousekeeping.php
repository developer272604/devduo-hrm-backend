<?php

namespace App\Console\Commands;

use App\Domain\Lifecycle\LifecycleJobs;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Applies approved future-dated moves that have come due and purges exit-interview answers past retention. Idempotent. */
class LifecycleHousekeeping extends Command
{
    protected $signature = 'lifecycle:housekeeping {--tenant= : Only this tenant id}';

    protected $description = 'Apply due internal moves and purge expired exit-interview data';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, fn () => app(LifecycleJobs::class)->housekeeping())));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
