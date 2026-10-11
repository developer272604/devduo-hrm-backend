<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/**
 * A raw punch: an immutable fact. Only the controlled-correction columns may change after insert
 * (processing bookkeeping, voiding, verification review). Location data may additionally be purged by the
 * retention command through purgeLocationData(). Everything else throws a LogicException; rows are never deleted.
 *
 * Note: mass query updates (Model::query()->update()) bypass model events by design of Eloquent; application code must
 * not use them on this table.
 */
class AttendanceEvent extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_events';

    public const CORRECTABLE = ['processing_state', 'processed_at', 'voided_at', 'voided_by', 'void_reason', 'verification_status', 'updated_at'];

    public const LOCATION_COLUMNS = ['latitude', 'longitude', 'accuracy_meters', 'photo_path'];

    public const SOURCES = ['web', 'mobile', 'biometric', 'import', 'api', 'admin', 'kiosk'];

    public const DIRECTIONS = ['IN', 'OUT', 'UNKNOWN'];

    private bool $purgingLocation = false;

    protected function casts(): array
    {
        return ['punched_at' => \App\Domain\Attendance\Capture\UtcInstant::class, 'local_date' => 'date:Y-m-d', 'raw_metadata' => 'array', 'imported_at' => 'datetime', 'processed_at' => 'datetime',
            'voided_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'accuracy_meters' => 'integer'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $event) {
            $allowed = self::CORRECTABLE;
            if ($event->purgingLocation) {
                $allowed = array_merge($allowed, self::LOCATION_COLUMNS);
            }
            $illegal = array_diff(array_keys($event->getDirty()), $allowed);
            if ($illegal !== []) {
                throw new \LogicException('Raw punches are immutable; refused to change: '.implode(', ', $illegal).'. Void the punch and record a new one instead.');
            }
        });
        static::deleting(function () {
            throw new \LogicException('Raw punches are never deleted. Void the punch instead.');
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function geofence()
    {
        return $this->belongsTo(Geofence::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Retention: remove precise location and photo reference. The ONLY mutation of fact columns that is permitted. */
    public function purgeLocationData(): bool
    {
        $this->purgingLocation = true;
        try {
            $this->forceFill(['latitude' => null, 'longitude' => null, 'accuracy_meters' => null, 'photo_path' => null]);

            return $this->save();
        } finally {
            $this->purgingLocation = false;
        }
    }

    /** Controlled correction used by the processing engine: mark as processed. */
    public function markProcessed(): void
    {
        $this->forceFill(['processing_state' => 'processed', 'processed_at' => now()])->save();
    }
}
