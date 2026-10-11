<?php

namespace App\Console\Commands;

use App\Domain\Experience\SurveyService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ExperiencePurgeSurveyData extends Command
{
    protected $signature = 'experience:purge-survey-data {--tenant= : Only this tenant id}';
    protected $description = 'Purge survey aggregates and participation after their retention date';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id') as $id) {
            try {
                $count = $runner->run((int) $id, fn () => app(SurveyService::class)->purgeExpiredData());
                $this->line("tenant {$id}: purged {$count} survey datasets");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("tenant {$id} failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
