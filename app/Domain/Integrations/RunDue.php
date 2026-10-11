<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Health\HealthService;
use App\Domain\Integrations\Imports\ImportProfileService;
use App\Domain\Integrations\Models\ImportProfile;
use App\Domain\Integrations\Models\JobRun;
use App\Domain\Integrations\Webhooks\WebhookService;
use App\Models\Tenant;
use App\Domain\Tenancy\TenantRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Scheduler entry point. Idempotent: webhook enqueue is cursor + unique-key based, file runs are keyed by content hash. */
final class RunDue
{
    public function __construct(private WebhookService $webhooks, private ImportProfileService $imports) {}

    /** @return array<string,int> */
    public function allTenants(): array
    {
        $totals = [];
        foreach (Tenant::cursor() as $tenant) {
            if (! in_array('integrations', $tenant->settings['modules'] ?? [], true)) {
                continue;
            }
            $lock = Cache::lock('integrations:run-due:lock:'.$tenant->id, 300);
            if (! $lock->get()) {
                continue; // another worker is already processing this tenant
            }
            try {
                foreach (app(TenantRunner::class)->run($tenant->id, fn () => $this->forCurrentTenant()) as $k => $v) {
                    $totals[$k] = ($totals[$k] ?? 0) + $v;
                }
            } finally {
                $lock->release();
            }
        }

        return $totals;
    }

    /** @return array<string,int> */
    public function forCurrentTenant(): array
    {
        $out = ['webhooks_queued' => $this->webhooks->enqueueEvents()];
        $delivery = $this->webhooks->deliverDue();
        $out += ['webhooks_sent' => $delivery['sent'], 'webhooks_failed' => $delivery['failed'], 'webhooks_dead' => $delivery['dead']];
        $out += ['files_processed' => 0, 'runs_retried' => 0, 'runs_finalized' => 0];

        foreach (ImportProfile::query()->where('status', 'active')->where('source', 'drop')->whereNotNull('interval_minutes')->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))->get() as $profile) {
            $out['files_processed'] += $this->scanDropFolder($profile);
            $profile->forceFill(['next_run_at' => now()->addMinutes($profile->interval_minutes)])->save();
        }
        foreach (JobRun::query()->where('status', 'failed')->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())->where('attempts', '<', ImportProfileService::MAX_AUTO_ATTEMPTS)->limit(20)->get() as $run) {
            $this->imports->execute($run);
            $out['runs_retried']++;
        }
        foreach (JobRun::query()->where('status', 'processing')->limit(50)->get() as $run) {
            $before = $run->status;
            if ($this->imports->finalize($run)->status !== $before) {
                $out['runs_finalized']++;
            }
        }
        HealthService::heartbeat();

        return $out;
    }

    private function scanDropFolder(ImportProfile $profile): int
    {
        $disk = Storage::disk('local');
        $dir = $this->imports->dropPath($profile);
        $ext = $profile->format === 'json' ? ['json'] : ['csv'];
        $count = 0;
        foreach ($disk->files($dir) as $file) {
            if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $ext, true) || $disk->size($file) > ImportProfileService::MAX_BYTES) {
                continue;
            }
            $run = $this->imports->run($profile, $disk->get($file), basename($file), false, 'schedule', null);
            // Content-hash idempotency: re-dropping the same bytes returns the earlier run. The file is archived either way.
            $disk->move($file, $dir.'/processed/'.now()->format('YmdHis').'-'.basename($file));
            $count += $run->wasRecentlyCreated ? 1 : 0;
        }

        return $count;
    }
}
