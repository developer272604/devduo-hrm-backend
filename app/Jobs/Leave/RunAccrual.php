<?php

namespace App\Jobs\Leave;

use App\Domain\Leave\Accrual\AccrualRunService;
use App\Domain\Leave\Models\LeaveAccrualRun;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Executes an accrual run in the background. Carries only trusted ids (tenant id, run id); sets the tenant context itself. Idempotent. */
class RunAccrual implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $tenantId, public int $runId, public ?int $userId = null) {}

    public function handle(TenantRunner $tenants, AccrualRunService $runs): void
    {
        $tenants->run($this->tenantId, function () use ($runs) {
            $run = LeaveAccrualRun::query()->find($this->runId);
            if (! $run || ! in_array($run->status, ['queued', 'preview', 'failed', 'partial'], true)) {
                return;
            }
            $user = $this->userId ? User::query()->where('tenant_id', $this->tenantId)->whereKey($this->userId)->first() : null;
            $runs->execute($run, $user);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('leave.accrual_job_failed', ['run_id' => $this->runId, 'tenant_id' => $this->tenantId, 'error' => $e->getMessage()]);
        app(TenantRunner::class)->run($this->tenantId, function () use ($e) {
            LeaveAccrualRun::query()->whereKey($this->runId)->where('status', 'running')->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
        });
    }
}
