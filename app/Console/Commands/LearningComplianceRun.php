<?php

namespace App\Console\Commands;

use App\Domain\Learning\ComplianceService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent: unique rule+employee+cycle assignments, My Work createOnce tasks, daily notice stamp and one-time escalation stamp. Schedule daily. */
class LearningComplianceRun extends Command
{
    protected $signature = 'learning:compliance-run {--tenant= : Only this tenant id}';

    protected $description = 'Assign mandatory training and escalate overdue assignments';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                if (! in_array('learning', Tenant::find($id)->settings['modules'] ?? [], true)) continue;
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, fn () => ['generated' => app(ComplianceService::class)->generate(), 'escalation' => app(ComplianceService::class)->escalate()])));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
