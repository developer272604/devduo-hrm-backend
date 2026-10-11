<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\FindingService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/** Idempotent per tenant: reconciles stored findings for every active user who holds intelligence.view. Fingerprints dedupe across runs. Schedule daily. */
class IntelligenceScan extends Command
{
    protected $signature = 'intelligence:scan {--tenant= : Only this tenant id}';

    protected $description = 'Refresh stored Intelligence Center findings for every eligible user';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                if (! in_array('intelligence', Tenant::find($id)->settings['modules'] ?? [], true)) {
                    continue;
                }
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, function () {
                    $totals = ['users' => 0, 'created' => 0, 'updated' => 0, 'reopened' => 0, 'auto_resolved' => 0];
                    foreach (User::where('active', true)->orderBy('id')->get() as $user) {
                        if (! $user->allows('intelligence.view')) {
                            continue;
                        }
                        $s = app(FindingService::class)->scan($user);
                        $totals['users']++;
                        foreach (['created', 'updated', 'reopened', 'auto_resolved'] as $k) {
                            $totals[$k] += $s[$k];
                        }
                    }

                    return $totals;
                })));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
