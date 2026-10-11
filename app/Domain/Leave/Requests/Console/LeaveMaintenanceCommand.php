<?php

namespace App\Domain\Leave\Requests\Console;

use App\Domain\Leave\CompOff\CompOffService;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Requests\DecisionService;
use App\Domain\Leave\Requests\RequestService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Escalates overdue pending leave approvals to HR and marks lapsed comp-off credits. Idempotent; hourly. */
class LeaveMaintenanceCommand extends Command
{
    protected $signature = 'leave:maintain-requests';

    protected $description = 'Escalate overdue leave approvals and expire lapsed comp-off credits';

    public function handle(TenantRunner $runner, DecisionService $decisions, RequestService $requests, CompOffService $compOff): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $n = $runner->run($tenantId, function () use ($decisions, $requests, $compOff) {
                $escalated = 0;
                $expired = 0;
                foreach (LeaveRequest::query()->where('status', 'pending')->get() as $r) {
                    if ($requests->expireIfOverdue($r)) {
                        $expired++;
                    } else {
                        $escalated += $decisions->escalateIfOverdue($r) ? 1 : 0;
                    }
                }

                return [$escalated, $expired, $compOff->expireDue()];
            });
            $this->line("Tenant $tenantId: $n[0] escalated, $n[1] leave request(s) expired, $n[2] comp-off credit(s) expired.");
        }

        return self::SUCCESS;
    }
}
