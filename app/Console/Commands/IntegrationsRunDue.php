<?php

namespace App\Console\Commands;

use App\Domain\Integrations\RunDue;
use Illuminate\Console\Command;

final class IntegrationsRunDue extends Command
{
    protected $signature = 'integrations:run-due';

    protected $description = 'Queue and deliver due webhooks, process file-drop import profiles and retry failed runs (idempotent, per tenant)';

    public function handle(RunDue $runner): int
    {
        $totals = $runner->allTenants();
        $this->info('integrations:run-due '.json_encode($totals));

        return self::SUCCESS;
    }
}
