<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Capture\Devices\DeviceProviders;
use App\Domain\Attendance\Capture\Devices\DeviceSyncService;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class AttendanceSyncDevices extends Command
{
    protected $signature = 'attendance:sync-devices {--force : Ignore the sync interval}';

    protected $description = 'Pull events from pull-model attendance devices that are due';

    public function handle(TenantRunner $runner, DeviceSyncService $sync, DeviceProviders $providers): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $runner->run($tenantId, function () use ($sync, $providers) {
                foreach (AttendanceDevice::query()->where('status', '!=', 'inactive')->get() as $device) {
                    if (! $providers->forDevice($device)->pulls()) {
                        continue;
                    }
                    if (! $this->option('force') && $device->last_sync_at && $device->last_sync_at->gt(now()->subMinutes($device->sync_interval_minutes))) {
                        continue;
                    }
                    $run = $sync->pull($device);
                    $this->line("Device {$device->name}: {$run->status}, {$run->inserted} inserted.");
                }
            });
        }

        return self::SUCCESS;
    }
}
