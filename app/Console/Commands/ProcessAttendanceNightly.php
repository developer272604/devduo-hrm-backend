<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessAttendanceNightly extends Command
{
    protected $signature = 'attendance:process-nightly {--tenant= : Only this tenant id}';

    protected $description = 'Nightly attendance recalculation (yesterday, the configured window and open days of today)';

    public function handle(): int
    {
        return $this->call('attendance:process', ['--nightly' => true] + ($this->option('tenant') ? ['--tenant' => $this->option('tenant')] : []));
    }
}
