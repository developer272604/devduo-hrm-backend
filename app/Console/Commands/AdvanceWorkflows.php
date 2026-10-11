<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Workflow\TriggerDispatcher;
use App\Domain\Workflow\WorkflowService;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class AdvanceWorkflows extends Command
{
    protected $signature = 'workflows:advance';

    protected $description = 'Advance due workflow timers and move overdue tasks into recovery';

    public function handle(WorkflowService $service): int
    {
        $context = app(TenantContext::class);
        $previous = $context->id;
        $count = 0;
        try {
            foreach (Tenant::cursor() as $tenant) {
                if (! in_array('workflows', $tenant->settings['modules'] ?? [], true)) {
                    continue;
                }
                $context->id = $tenant->id;
                if (in_array('people', $tenant->settings['modules'] ?? [], true)) {
                    $count += app(TriggerDispatcher::class)->dispatch();
                }
                $count += $service->tick();
            }
        } finally {
            $context->id = $previous;
        }
        $this->info("Processed $count due workflows.");

        return self::SUCCESS;
    }
}
