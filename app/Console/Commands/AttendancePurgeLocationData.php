<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Boot\CaptureBoot;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Tenancy\TenantRunner;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AttendancePurgeLocationData extends Command
{
    protected $signature = 'attendance:purge-location-data';

    protected $description = 'Remove precise coordinates and photos from punches older than the tenant location retention period';

    public function handle(TenantRunner $runner): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $runner->run($tenantId, function () use ($tenantId) {
                $days = max(1, (int) AttendanceSettings::get('location.retention_days', 90));
                $cutoff = now('UTC')->subDays($days)->format('Y-m-d H:i:s');
                $count = 0;
                AttendanceEvent::query()->where('punched_at', '<', $cutoff)->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('longitude')->orWhereNotNull('photo_path'))
                    ->chunkById(200, function ($events) use (&$count) {
                        foreach ($events as $e) {
                            $photo = $e->photo_path;
                            $e->purgeLocationData();
                            $photo && Storage::disk(CaptureBoot::DISK)->delete($photo);
                            $count++;
                        }
                    });
                if ($count) {
                    AuditLog::create(['user_id' => null, 'actor' => 'System', 'action' => 'location_data_purged', 'module' => 'attendance', 'resource' => 'attendance_events', 'ip_address' => null, 'context' => ['count' => $count, 'retention_days' => $days]]);
                }
                $this->line("Tenant $tenantId: purged $count punch(es).");
            });
        }

        return self::SUCCESS;
    }
}
