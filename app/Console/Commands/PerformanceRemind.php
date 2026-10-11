<?php

namespace App\Console\Commands;

use App\Domain\Performance\PerformanceJobs;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent: reminded_at stamps limit each pending review response / PIP checkpoint to one reminder per day. Schedule daily. */
class PerformanceRemind extends Command
{
    protected $signature = 'performance:remind {--tenant= : Only this tenant id}';

    protected $description = 'Remind reviewers of review responses due soon or overdue, and PIP owners of due checkpoints';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                if (! in_array('performance', Tenant::find($id)->settings['modules'] ?? [], true)) continue;
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, fn () => app(PerformanceJobs::class)->remind())));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
