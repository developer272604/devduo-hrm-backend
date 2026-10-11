<?php

namespace App\Console\Commands;

use App\Domain\Experience\HelpdeskService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ExperienceEscalateSlaTickets extends Command
{
    protected $signature = 'experience:escalate-sla-tickets {--tenant= : Only this tenant id}';
    protected $description = 'Escalate overdue unresolved HR tickets and notify service owners';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $count = $runner->run((int) $id, fn () => app(HelpdeskService::class)->escalateSlaBreaches());
                $this->line("tenant {$id}: escalated {$count} overdue tickets");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
