<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent per tenant and reminder window. Schedule daily. */
class ProbationReminders extends Command
{
    protected $signature = 'people:probation-reminders {--tenant= : Only this tenant id}';

    protected $description = 'Create My Work tasks and notifications 14 and 3 days before probation ends';

    public function handle(TenantRunner $runner): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $result = $runner->run((int) $id, fn () => app(\App\Domain\People\Insights\LifecycleJobs::class)->probationReminders());
                $this->line('tenant '.$id.': '.json_encode($result));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
