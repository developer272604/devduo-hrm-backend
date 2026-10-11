<?php

namespace App\Console\Commands;

use App\Domain\Experience\SurveyDeliveryService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ExperienceDeliverSurveys extends Command
{
    protected $signature = 'experience:deliver-surveys {--tenant= : Only this tenant id}';
    protected $description = 'Send eligible survey invitations and preference-aware reminders';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $count = $runner->run((int) $id, fn () => app(SurveyDeliveryService::class)->deliverDue());
                $this->line("tenant {$id}: processed {$count} survey campaigns");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
