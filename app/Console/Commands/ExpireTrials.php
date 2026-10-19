<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\AccountLifecycle;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    protected $signature = 'trials:expire';

    protected $description = 'Suspend workspaces whose 24-hour free trial has ended (they then await owner approval)';

    public function handle(AccountLifecycle $lifecycle): int
    {
        $this->info($lifecycle->expireDue().' trial(s) suspended.');

        return self::SUCCESS;
    }
}
