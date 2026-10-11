<?php

namespace App\Jobs\People;

use App\Domain\People\Models\EmployeeExport;
use App\Domain\People\Operations\ExportService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEmployeeExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $tenantId, public int $exportId, public int $userId) {}

    public function handle(TenantRunner $runner, ExportService $service): void
    {
        $runner->run($this->tenantId, function () use ($service) {
            $export = EmployeeExport::query()->findOrFail($this->exportId);
            $user = User::query()->where('tenant_id', $this->tenantId)->findOrFail($this->userId);
            $service->run($export, $user);
        });
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        app(TenantRunner::class)->run($this->tenantId, function () {
            EmployeeExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'The export could not be completed. Try again or contact support.']);
        });
    }
}
