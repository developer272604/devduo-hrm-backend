<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\ProcessingRun;
use App\Domain\Attendance\Processing\RecalculationService;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** attendance:process (manual range) and, with --nightly, the nightly recalculation (yesterday + the configured window + today's open days). */
class ProcessAttendance extends Command
{
    protected $signature = 'attendance:process {--tenant= : Only this tenant id} {--from= : First date Y-m-d} {--to= : Last date Y-m-d} {--nightly : Nightly window}';

    protected $description = 'Calculate attendance for every tenant (or one) over a date range';

    public function handle(TenantRunner $runner): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $line = $runner->run((int) $id, function () {
                    $today = TenantClock::today();
                    if ($this->option('nightly')) {
                        $days = max(1, (int) AttendanceSettings::get('processing.nightly_recalculation_days', 3));
                        $from = CarbonImmutable::parse($today)->subDays($days)->toDateString();
                        $to = $today;
                        $trigger = 'nightly';
                    } else {
                        $from = $this->option('from') ?: CarbonImmutable::parse($today)->subDay()->toDateString();
                        $to = $this->option('to') ?: $from;
                        $trigger = 'scheduled';
                    }
                    $uuid = app(RecalculationService::class)->recalculate(['tenant' => true, 'from' => $from, 'to' => $to], $trigger);
                    $run = ProcessingRun::byUuid($uuid);

                    return "$from..$to run $uuid {$run->status} processed={$run->processed_days} failed={$run->failed_days} locked={$run->skipped_locked}";
                });
                $this->line('tenant '.$id.': '.$line);
            } catch (\Throwable $e) {
                Log::error('attendance.command_failed', ['tenant' => $id, 'error' => $e->getMessage()]);
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
