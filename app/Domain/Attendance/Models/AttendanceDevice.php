<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use Illuminate\Support\Facades\Crypt;

class AttendanceDevice extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_devices';

    protected $hidden = ['config_encrypted', 'auth_token_hash'];

    public const TYPES = ['biometric', 'rfid', 'face', 'kiosk', 'mobile', 'other'];

    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_heartbeat' => 'array', 'token_issued_at' => 'datetime'];
    }

    public function mappings()
    {
        return $this->hasMany(EmployeeDeviceMapping::class, 'device_id');
    }

    public function syncRuns()
    {
        return $this->hasMany(DeviceSyncRun::class, 'device_id');
    }

    public function config(): array
    {
        if (! $this->config_encrypted) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($this->config_encrypted), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setConfig(?array $config): void
    {
        $this->config_encrypted = $config ? Crypt::encryptString(json_encode($config)) : null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
