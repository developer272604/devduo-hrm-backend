<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\OnDutyRequest;
use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Requests\OnDutyService;
use App\Domain\Attendance\Requests\OvertimeService;
use App\Domain\Attendance\Requests\RegularizationService;
use App\Domain\Attendance\Requests\RequestRules;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Closes pending requests past their cutoff (regularization_days_after) visibly, telling the requester. Idempotent. Recommended: daily at 01:00. */
class AttendanceExpireRequests extends Command
{
    protected $signature = 'attendance:expire-requests';

    protected $description = 'Expire pending attendance requests that passed the regularization cutoff';

    public function handle(TenantRunner $runner, RegularizationService $reg, OvertimeService $ot, OnDutyService $duty): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $n = $runner->run($tenantId, function () use ($reg, $ot, $duty) {
                $days = (int) RequestRules::setting('cutoffs.regularization_days_after', 5);
                $cut = CarbonImmutable::parse(TenantClock::today())->subDays($days)->toDateString();
                $count = 0;
                foreach (Regularization::query()->where('status', 'pending')->where('attendance_date', '<', $cut)->get() as $x) {
                    $count += $reg->expire($x) ? 1 : 0;
                }
                foreach (OvertimeRequest::query()->where('status', 'pending')->where('work_date', '<', $cut)->get() as $x) {
                    $count += $ot->expire($x) ? 1 : 0;
                }
                foreach (OnDutyRequest::query()->where('status', 'pending')->where('end_date', '<', $cut)->get() as $x) {
                    $count += $duty->expire($x) ? 1 : 0;
                }

                return $count;
            });
            $this->line("Tenant $tenantId: $n request(s) expired.");
        }

        return self::SUCCESS;
    }
}
