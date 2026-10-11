<?php

namespace App\Jobs\Payroll;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Payslips\PayslipService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Idempotent: re-running creates no duplicate payslip (same result => skipped). Carries trusted ids only and sets tenant context itself. */
class GeneratePayslipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $tenantId, public int $runId, public ?int $actorId = null) {}

    public function handle(TenantRunner $runner, PayslipService $service): void
    {
        $runner->run($this->tenantId, function () use ($service) {
            $service->generateForRun(PayrollRun::query()->findOrFail($this->runId), $this->actorId ? User::find($this->actorId) : null);
        });
    }
}
