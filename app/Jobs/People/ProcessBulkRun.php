<?php

namespace App\Jobs\People;

use App\Domain\People\Models\BulkRun;
use App\Domain\People\Operations\BulkService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBulkRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $tenantId, public int $runId, public int $userId) {}

    public function handle(TenantRunner $runner, BulkService $service): void
    {
        $runner->run($this->tenantId, function () use ($service) {
            $run = BulkRun::query()->findOrFail($this->runId);
            $user = User::query()->where('tenant_id', $this->tenantId)->findOrFail($this->userId);
            $service->execute($run, $user);
        });
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        app(TenantRunner::class)->run($this->tenantId, fn () => BulkRun::query()->whereKey($this->runId)->update(['status' => 'failed', 'finished_at' => now()]));
    }
}
