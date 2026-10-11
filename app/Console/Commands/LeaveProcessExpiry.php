<?php

namespace App\Console\Commands;

use App\Domain\Leave\CarryForward\CarryForwardService;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Nightly: expire unused leave lots, send expiry alerts and (when accrual.auto_carry_forward is on) close ended periods. Idempotent per lot / account. */
class LeaveProcessExpiry extends Command
{
    protected $signature = 'leave:process-expiry {--tenant= : Only this tenant id} {--as-of= : Y-m-d (default: tenant today)} {--dry-run : Report without posting}';

    protected $description = 'Expire due leave balances, send expiry alerts and run automatic carry-forward';

    public function handle(TenantRunner $runner, ExpiryService $expiry, CarryForwardService $carry): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            try {
                $line = $runner->run((int) $id, function () use ($expiry, $carry) {
                    $asOf = $this->option('as-of') ?: null;
                    $r = $expiry->process($asOf, null, (bool) $this->option('dry-run'));
                    $line = "expired={$r['expired']} units={$r['units']} fully_used={$r['already_used']}";
                    if (! $this->option('dry-run')) {
                        $line .= ' notified='.$expiry->alerts($asOf)['notified'];
                        if (LeaveSettings::get('accrual.auto_carry_forward', false)) {
                            $c = $carry->run(['as_of' => $asOf ?: \App\Domain\Tenancy\TenantClock::today()]);
                            $line .= ' carry_items='.count($c['items']);
                        }
                    }

                    return $line;
                });
                $this->line('tenant '.$id.': '.$line);
            } catch (\Throwable $e) {
                Log::error('leave.process_expiry_failed', ['tenant' => $id, 'error' => $e->getMessage()]);
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
