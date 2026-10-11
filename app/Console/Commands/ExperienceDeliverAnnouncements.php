<?php

namespace App\Console\Commands;

use App\Domain\Experience\AnnouncementDeliveryService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ExperienceDeliverAnnouncements extends Command
{
    protected $signature = 'experience:deliver-announcements {--tenant= : Only this tenant id}';
    protected $description = 'Deliver due employee announcement notifications using each recipient’s communication preferences';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $count = $runner->run((int) $id, fn () => app(AnnouncementDeliveryService::class)->deliverDue());
                $this->line("tenant {$id}: processed {$count} announcements");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
