<?php

namespace App\Domain\Analytics;

use App\Domain\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Carries only trusted ids; idempotent because ExportJobService ignores jobs that are no longer queued. */
class RunAnalyticsExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $tenantId, public string $jobUuid) {}

    public function handle(TenantRunner $tenants, ExportJobService $exports): void
    {
        $tenants->run($this->tenantId, fn () => $exports->process($this->jobUuid));
    }
}
