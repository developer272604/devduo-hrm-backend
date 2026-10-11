<?php

namespace App\Console\Commands;

use App\Domain\Leave\Accrual\AccrualRunService;
use App\Domain\Tenancy\TenantRunner;
use App\Jobs\Leave\RunAccrual;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly leave accrual for every tenant (or one). Uses a deterministic run key per day and the catch-up window from settings, and
 * ignores occurrences already credited, so running it twice (or after a missed night) never double-credits. Queued unless --sync.
 */
class LeaveAccrueNightly extends Command
{
    protected $signature = 'leave:accrue-nightly {--tenant= : Only this tenant id} {--sync : Run in this process instead of the queue}';

    protected $description = 'Post due leave accruals for the catch-up window (idempotent)';

    public function handle(TenantRunner $runner, AccrualRunService $runs): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $line = $runner->run((int) $id, function () use ($runs, $id) {
                    $params = $runs->nightlyParams();
                    if (! $params) {
                        return 'disabled';
                    }
                    $run = $runs->findOrCreate($params);
                    if ($run->status === 'completed') {
                        return 'already completed ('.$run->run_key.')';
                    }
                    if ($this->option('sync')) {
                        $run = $runs->execute($run);

                        return "run {$run->uuid} {$run->status} posted={$run->posted_items} skipped={$run->skipped_items} failed={$run->failed_items}";
                    }
                    $run->forceFill(['status' => 'queued'])->save();
                    RunAccrual::dispatch((int) $id, $run->id, null);

                    return 'queued run '.$run->uuid;
                });
                $this->line('tenant '.$id.': '.$line);
            } catch (\Throwable $e) {
                Log::error('leave.accrue_nightly_failed', ['tenant' => $id, 'error' => $e->getMessage()]);
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
