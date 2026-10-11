<?php

namespace App\Domain\Payroll\Runs;

use App\Domain\Payroll\Engine\RunCalculator;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One chunk of employees of a calculation pass. Carries only trusted ids (tenant, run uuid, pass number, run-employee ids). Idempotent and
 * retry-safe: employees already processed in this pass (calc_pass) are skipped, a superseded pass is ignored, and finalization happens once.
 */
class CalculateRunChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /** @param array<int,int> $runEmployeeIds */
    public function __construct(public int $tenantId, public string $runUuid, public int $pass, public array $runEmployeeIds) {}

    public function handle(TenantRunner $tenants, RunCalculator $calculator): void
    {
        $tenants->run($this->tenantId, function () use ($calculator) {
            $run = PayrollRun::where('uuid', $this->runUuid)->first();
            if (! $run || $run->calculation_version !== $this->pass || $run->status !== 'calculating') {
                return;
            }
            $calculator->processChunk($run, $this->pass, $this->runEmployeeIds);
        });
    }
}
