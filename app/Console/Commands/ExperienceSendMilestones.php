<?php

namespace App\Console\Commands;

use App\Domain\Experience\EmployeeMilestoneService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ExperienceSendMilestones extends Command
{
    protected $signature = 'experience:send-milestones {--tenant= : Only this tenant id}';
    protected $description = 'Send preference-aware birthday and work-anniversary notices privately to each employee';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $count = $runner->run((int) $id, fn () => app(EmployeeMilestoneService::class)->notifyToday());
                $this->line("tenant {$id}: delivered {$count} milestone notices");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
