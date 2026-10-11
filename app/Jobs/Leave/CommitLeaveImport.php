<?php

namespace App\Jobs\Leave;

use App\Domain\Leave\Imports\ImportService;
use App\Domain\Leave\Models\LeaveImport;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Commits a validated leave import in the background. Carries only trusted ids; sets tenant context itself. */
class CommitLeaveImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $tenantId, public int $importId, public ?int $requestedBy = null) {}

    public function handle(TenantRunner $runner, ImportService $service): void
    {
        $runner->run($this->tenantId, fn () => $service->commit(LeaveImport::query()->findOrFail($this->importId), $this->requestedBy));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('leave.import_job_failed', ['import_id' => $this->importId, 'tenant_id' => $this->tenantId, 'error' => $e->getMessage()]);
        app(TenantRunner::class)->run($this->tenantId, function () {
            LeaveImport::query()->whereKey($this->importId)->whereNotIn('status', ['completed', 'completed_with_errors'])->update(['status' => 'failed', 'finished_at' => now()]);
        });
    }
}
