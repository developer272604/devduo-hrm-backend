<?php

namespace App\Console\Commands;

use App\Domain\Recruitment\Models\PortalChallenge;
use App\Domain\Recruitment\Models\Submission;
use App\Domain\Recruitment\RecruitmentHousekeeping as Housekeeping;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class RecruitmentHousekeeping extends Command
{
    protected $signature = 'recruitment:housekeeping {--tenant= : Only this tenant id}';

    protected $description = 'Expire local offers/postings and send in-app stage and feedback reminders';

    public function handle(TenantRunner $runner): int
    {
        $failed = false;
        foreach (Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->orderBy('id')->cursor() as $tenant) {
            try {
                $runner->run($tenant->id, fn () => PortalChallenge::where('expires_at', '<=', now())->delete());
                $runner->run($tenant->id, fn () => Submission::where('expires_at', '<=', now())->delete());
                if (! in_array('recruitment', $tenant->settings['modules'] ?? [], true)) {
                    continue;
                }
                $counts = $runner->run($tenant->id, fn () => app(Housekeeping::class)->run());
                $this->line('tenant '.$tenant->id.': '.json_encode($counts));
            } catch (\Throwable $error) {
                $failed = true;
                report($error);
                $this->error('Recruitment housekeeping failed for tenant '.$tenant->id.'. See application logs.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
