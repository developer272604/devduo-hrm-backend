<?php

namespace App\Domain\Attendance\Capture;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use App\Models\User;

/**
 * The ONE place punches are serialized for APIs (location privacy, spec 22).
 *
 * Precise coordinates (latitude, longitude, accuracy) are included only for (a) the owner of the punch, or (b) a viewer
 * holding attendance.location.view who is either tenant-wide or allowed by the tenant setting location.managers_see_precise.
 * Everyone else only gets geofence status and the place name. Every precise read by a non-owner is audited
 * ("location_viewed"), once per presenter call with the list of punch uuids.
 */
class PunchPresenter
{
    private ?array $own = null;

    public function present(AttendanceEvent $event, User $viewer, bool $audit = true): array
    {
        return $this->presentMany([$event], $viewer, $audit)[0];
    }

    /** @param iterable<AttendanceEvent> $events @return array<int,array> */
    public function presentMany(iterable $events, User $viewer, bool $audit = true): array
    {
        $out = [];
        $revealed = [];
        foreach ($events as $event) {
            $row = $this->base($event);
            $hasPrecise = $event->latitude !== null && $event->longitude !== null;
            if ($hasPrecise && $this->canSeePrecise($viewer, $event)) {
                $row['location'] = ['latitude' => $event->latitude, 'longitude' => $event->longitude, 'accuracy_meters' => $event->accuracy_meters];
                if (! $this->isOwner($viewer, $event)) {
                    $revealed[] = $event->uuid;
                }
            } else {
                $row['location'] = null;
            }
            $row['location_captured'] = $hasPrecise;
            $out[] = $row;
        }
        if ($audit && $revealed !== []) {
            Audit::record('location_viewed', 'attendance', 'attendance_events', ['count' => count($revealed), 'events' => array_slice($revealed, 0, 100)]);
        }

        return $out;
    }

    public function canSeePrecise(User $viewer, AttendanceEvent $event): bool
    {
        if ($this->isOwner($viewer, $event)) {
            return true;
        }
        if (! $viewer->allows('attendance.location.view')) {
            return false;
        }

        return app(DataScope::class)->hasTenantWide($viewer, 'attendance.location.view') || (bool) AttendanceSettings::get('location.managers_see_precise', false);
    }

    private function isOwner(User $viewer, AttendanceEvent $event): bool
    {
        $this->own ??= app(DataScope::class)->ownEmployeeIds($viewer);

        return in_array($event->employee_id, $this->own, true);
    }

    private function base(AttendanceEvent $e): array
    {
        $local = TimeZones::utcToLocal($e->punched_at, $e->timezone);
        $employee = $e->relationLoaded('employee') ? $e->employee : null;

        return [
            'id' => $e->uuid,
            'employee' => $employee ? ['id' => $employee->uuid, 'employee_number' => $employee->employee_number, 'name' => $employee->relationLoaded('person') ? $employee->person?->display_name : null] : null,
            'punched_at' => $e->punched_at->toIso8601String(),
            'local_time' => $local->format('Y-m-d H:i:s'),
            'local_date' => $e->local_date->toDateString(),
            'timezone' => $e->timezone,
            'direction' => $e->direction,
            'source' => $e->source,
            'device' => $e->device_id && $e->relationLoaded('device') && $e->device ? ['id' => $e->device->uuid, 'name' => $e->device->name] : null,
            'geofence_status' => $e->geofence_status,
            'place' => $e->relationLoaded('geofence') ? $e->geofence?->name : null,
            'verification_status' => $e->verification_status,
            'has_photo' => $e->photo_path !== null,
            'processing_state' => $e->processing_state,
            'voided' => $e->voided_at !== null,
            'voided_at' => $e->voided_at?->toIso8601String(),
            'void_reason' => $e->void_reason,
            'late_sync' => (bool) ($e->raw_metadata['late_sync'] ?? false),
            'created_by_admin' => $e->source === 'admin',
        ];
    }
}
