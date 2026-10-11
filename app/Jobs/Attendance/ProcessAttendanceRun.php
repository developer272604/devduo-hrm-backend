<?php

namespace App\Jobs\Attendance;

use App\Domain\Attendance\Models\ProcessingRun;
use App\Domain\Attendance\Processing\RecalculationService;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Executes one attendance_processing_runs record inside its tenant. Re-running is idempotent (results are recomputed; unchanged days only refresh calculated_at). */
class ProcessAttendanceRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public int $tenantId, public int $runId) {}

    /** @return array<int,int> seconds between retries */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TenantRunner $tenants, RecalculationService $service): void
    {
        $tenants->run($this->tenantId, function () use ($service) {
            $run = ProcessingRun::query()->find($this->runId);
            if ($run && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
                $service->execute($run);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        app(TenantRunner::class)->run($this->tenantId, function () use ($e) {
            if ($run = ProcessingRun::query()->find($this->runId)) {
                app(RecalculationService::class)->fail($run, $e);
            }
        });
    }
}
