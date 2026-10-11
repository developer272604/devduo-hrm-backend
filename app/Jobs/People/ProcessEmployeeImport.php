<?php

namespace App\Jobs\People;

use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Operations\ImportProcessor;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Commits a validated employee import. Idempotent: re-running skips rows that were already imported. */
class ProcessEmployeeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $tenantId, public int $importId, public int $userId) {}

    public function handle(TenantRunner $runner, ImportProcessor $processor): void
    {
        $runner->run($this->tenantId, function () use ($processor) {
            $import = EmployeeImport::query()->findOrFail($this->importId);
            $user = User::query()->where('tenant_id', $this->tenantId)->findOrFail($this->userId);
            $processor->run($import, $user);
        });
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        app(TenantRunner::class)->run($this->tenantId, function () {
            EmployeeImport::query()->whereKey($this->importId)->where('status', 'processing')->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'The import stopped unexpectedly. Retry it, or contact support if it keeps happening.']);
        });
    }
}
