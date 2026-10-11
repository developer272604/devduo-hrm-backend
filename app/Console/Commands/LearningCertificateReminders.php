<?php

namespace App\Console\Commands;

use App\Domain\Learning\CertificateService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Idempotent: one reminder per threshold (last_reminder_days), expired certificates are marked once, tasks use createOnce. Schedule daily. */
class LearningCertificateReminders extends Command
{
    protected $signature = 'learning:certificate-reminders {--tenant= : Only this tenant id}';

    protected $description = 'Mark expired certificates and send renewal reminders';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                if (! in_array('learning', Tenant::find($id)->settings['modules'] ?? [], true)) continue;
                $this->line('tenant '.$id.': '.json_encode($runner->run((int) $id, fn () => app(CertificateService::class)->processReminders())));
            } catch (\Throwable $e) {
                $this->error('tenant '.$id.' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
