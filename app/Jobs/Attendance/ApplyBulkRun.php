<?php

namespace App\Jobs\Attendance;

use App\Domain\Attendance\Models\AttendanceBulkRun;
use App\Domain\Attendance\Requests\BulkRunner;
use App\Domain\Tenancy\TenantRunner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Applies a confirmed attendance bulk run in the background (runs above 100 targets). Carries only trusted ids. */
class ApplyBulkRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $tenantId, public string $runUuid, public int $userId) {}

    public function handle(TenantRunner $tenants, BulkRunner $runner): void
    {
        $tenants->run($this->tenantId, function () use ($runner) {
            $user = User::query()->where('tenant_id', $this->tenantId)->whereKey($this->userId)->first();
            $run = AttendanceBulkRun::query()->where('uuid', $this->runUuid)->first();
            if (! $user || ! $run || ! in_array($run->status, ['queued', 'processing'], true)) {
                return;
            }
            $previous = auth()->user();
            auth()->setUser($user);
            try {
                $runner->execute($run, $user);
            } finally {
                $previous ? auth()->setUser($previous) : auth()->forgetUser();
            }
        });
    }
}
