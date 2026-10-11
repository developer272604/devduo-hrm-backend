<?php

namespace App\Jobs\Attendance;

use App\Domain\Attendance\Capture\Import\AttendanceImportService;
use App\Domain\Attendance\Models\AttendanceImport;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Commits (or validates, phase "validate") an attendance import in the background. Carries only trusted ids; sets tenant context itself. */
class ProcessAttendanceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $tenantId, public int $importId, public string $phase = 'commit', public ?int $requestedBy = null) {}

    public function handle(TenantRunner $runner, AttendanceImportService $service): void
    {
        $runner->run($this->tenantId, function () use ($service) {
            $import = AttendanceImport::query()->findOrFail($this->importId);
            $this->phase === 'validate' ? $service->validate($import) : $service->commit($import, $this->requestedBy);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('attendance.import_failed', ['import_id' => $this->importId, 'tenant_id' => $this->tenantId, 'phase' => $this->phase, 'error' => $e->getMessage()]);
        app(TenantRunner::class)->run($this->tenantId, function () {
            AttendanceImport::query()->whereKey($this->importId)->where('status', '!=', 'completed')->update(['status' => 'failed', 'finished_at' => now()]);
        });
    }
}
