<?php

namespace App\Domain\Attendance\Capture;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The single entry point for storing raw punches, whatever the source (web, mobile, biometric, import, api, admin, kiosk).
 *
 * Input keys for record(): employee (Employee, required) | source (required) | direction (IN|OUT|UNKNOWN|null) | at (instant; ignored for
 * web/mobile/kiosk, which always use SERVER time) | device (AttendanceDevice) | location_id | latitude, longitude, accuracy | ip |
 * client_device (device fingerprint) | external_ref | idempotency_key | metadata (array, stored in raw_metadata) | import_id | photo (UploadedFile) |
 * actor (User) | allow_old (bool, imports) | recalculate (bool, default true; batch callers pass false and recalculate once) | emit_events (bool, default true).
 *
 * Policy rules come from App\Domain\Attendance\Policies\PolicyResolver when that class exists, otherwise from PolicyRules::DEFAULTS.
 * Tests (or other modules) may bind a callable under 'attendance.punch_rules' (fn(Employee, string $date): ?array) to supply rules.
 */
class PunchService
{
    public const LIVE_SOURCES = ['web', 'mobile', 'kiosk'];

    public function __construct(private GeofenceService $geofences) {}

    public function record(array $in): PunchResult
    {
        /** @var Employee $employee */
        $employee = $in['employee'];
        $source = (string) ($in['source'] ?? '');
        if (! in_array($source, AttendanceEvent::SOURCES, true)) {
            throw new PunchRejected('invalid_source', 'Unknown punch source.');
        }
        if (! AttendanceSettings::methodEnabled($source)) {
            throw new PunchRejected('method_disabled', 'This way of recording attendance is not turned on for your company. Please contact HR.', 403);
        }
        $live = in_array($source, self::LIVE_SOURCES, true);
        $now = CarbonImmutable::now('UTC');
        $device = $in['device'] ?? null;
        $actor = $in['actor'] ?? null;
        $metadata = $in['metadata'] ?? [];

        if ($live || ($source === 'admin' && empty($in['at']))) {
            $at = $now;
        } elseif (! empty($in['at'])) {
            $at = CarbonImmutable::parse($in['at'])->utc();
        } else {
            throw new PunchRejected('missing_time', 'The punch has no time.');
        }
        if (! $live && $at->gt($now->addMinutes(5))) {
            throw new PunchRejected('future_punch', 'The punch time is in the future. Check the device clock or the file.');
        }
        if (in_array($source, ['biometric', 'api', 'kiosk'], true) && empty($in['allow_old'])) {
            $maxAge = (int) AttendanceSettings::get('punch.max_age_days', 31);
            if ($at->lt($now->subDays($maxAge))) {
                throw new PunchRejected('too_old', "This event is older than $maxAge days and was not accepted. Import it from a file instead.");
            }
        }
        if ($source === 'biometric' || $source === 'api') {
            $lateMinutes = (int) AttendanceSettings::get('punch.late_sync_minutes', 60);
            if ($at->lt($now->subMinutes($lateMinutes))) {
                $metadata['late_sync'] = true;
            }
        }

        [$zone, $zoneSource] = $this->zoneFor($employee, $device, $in['location_id'] ?? null);
        $localDate = TimeZones::localDate($at, $zone);
        $this->assertEmployed($employee, $source, $localDate);

        if (app()->bound(LockGuard::class)) {
            app(LockGuard::class)->assertUnlocked($employee->id, $localDate);
        }

        $rules = $this->rulesFor($employee, $localDate);
        $restrictions = $rules['punch_restrictions'] ?? [];
        if (! in_array($source, $rules['methods'] ?? [], true)) {
            throw new PunchRejected('method_not_allowed', 'Your attendance policy does not allow punching this way.', 403);
        }
        if ($live && ($ranges = $restrictions['allowed_ip_ranges'] ?? []) && ! IpUtils::checkIp((string) ($in['ip'] ?? ''), $ranges)) {
            throw new PunchRejected('ip_not_allowed', 'You can only record attendance from your company network.', 403);
        }

        // ---- location & geofence (privacy: only web/mobile punches, only when tenant mode AND policy ask for it) -----
        $lat = $lng = $accuracy = null;
        $geo = ['status' => $live || $source === 'admin' ? 'not_checked' : 'not_required', 'geofence_id' => null, 'distance_meters' => null, 'mode' => 'anywhere', 'exempt' => null];
        if (in_array($source, ['web', 'mobile'], true)) {
            $mode = AttendanceSettings::get('location.mode', 'off');
            $capture = $mode !== 'off' && ! empty($restrictions['capture_location']);
            if ($capture) {
                [$lat, $lng, $accuracy] = $this->coordinates($in);
                if ($lat === null && $mode === 'required') {
                    throw new PunchRejected('location_required', 'Your company needs your location to record attendance. Turn on location sharing for this site and try again.');
                }
            }
            $geo = $this->geofences->evaluate($lat, $lng, $accuracy, $employee, $restrictions, $localDate);
            if (! $capture && $geo['status'] === 'unavailable') {
                $geo['status'] = 'not_checked';
            }
            if ($geo['status'] === 'outside' && AttendanceSettings::get('location.enforcement', 'store') === 'block') {
                throw new PunchRejected('outside_geofence', "You appear to be outside an approved work location, so this punch wasn't recorded. Move to your work location and try again, or ask your manager to add a manual entry.");
            }
        }
        $photo = $in['photo'] ?? null;
        $requirePhoto = in_array($source, ['web', 'mobile'], true) && ! empty($restrictions['require_photo']);
        if ($requirePhoto && ! $photo) {
            throw new PunchRejected('photo_required', 'A photo is required to record attendance. Take a photo and try again.');
        }

        $requested = isset($in['direction']) ? strtoupper((string) $in['direction']) : null;
        $requested = in_array($requested, AttendanceEvent::DIRECTIONS, true) ? $requested : null;
        $fingerprint = hash('sha256', json_encode([$source, $requested ?? 'auto', $device?->id, $in['external_ref'] ?? null, $live ? null : $at->timestamp, $in['employee']->id]));
        $window = max(0, (int) AttendanceSettings::get('punch.duplicate_window_seconds', 60));

        $photoPath = null;
        try {
            $result = DB::transaction(function () use ($in, $employee, $source, $live, $at, $zone, $zoneSource, $localDate, $device, $actor, $metadata, $rules, $restrictions, $lat, $lng, $accuracy, $geo, $photo, $requirePhoto, $requested, $fingerprint, $window, &$photoPath) {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first(); // serializes punches per employee
                $key = $in['idempotency_key'] ?? null;
                if ($key) {
                    $existing = AttendanceEvent::query()->where('employee_id', $employee->id)->where('idempotency_key', $key)->first();
                    if ($existing) {
                        if (($existing->raw_metadata['payload_hash'] ?? null) !== $fingerprint) {
                            throw new PunchRejected('idempotency_conflict', 'This request key was already used for a different punch. Create a new request key and try again.', 409);
                        }

                        return new PunchResult($existing, true, 'idempotency_key', $geo);
                    }
                }
                $ref = $in['external_ref'] ?? null;
                if ($ref !== null) {
                    $existing = AttendanceEvent::query()->where('external_ref', $ref)->where('device_id', $device?->id)->where('employee_id', $employee->id)->first();
                    if ($existing) {
                        return new PunchResult($existing, true, 'external_ref', $geo);
                    }
                }

                $last = AttendanceEvent::query()->where('employee_id', $employee->id)->whereNull('voided_at')->where('punched_at', '<=', $at)->orderByDesc('punched_at')->orderByDesc('id')->first();
                $derived = false;
                $direction = $requested;
                if ($direction === null) {
                    if ($live) {
                        $direction = $last?->direction === 'IN' ? 'OUT' : 'IN';
                        $derived = true;
                    } else {
                        $direction = 'UNKNOWN';
                    }
                }
                if ($derived && $last && $last->punched_at->gte($at->subSeconds($window))) {
                    return new PunchResult($last, true, 'duplicate_window', $geo); // double click / repeated tap
                }
                if ($source !== 'admin') {
                    $near = AttendanceEvent::query()->where('employee_id', $employee->id)->whereNull('voided_at')->where('direction', $direction)
                        ->whereBetween('punched_at', [$at->subSeconds($window), $at->addSeconds($window)])->orderBy('id')->first();
                    if ($near) {
                        return new PunchResult($near, true, 'duplicate_window', $geo);
                    }
                }
                if ($live) {
                    $max = (int) ($restrictions['max_punches_per_day'] ?? 0);
                    if ($max > 0 && AttendanceEvent::query()->where('employee_id', $employee->id)->where('local_date', $localDate)->whereNull('voided_at')->count() >= $max) {
                        throw new PunchRejected('too_many_punches', "You have reached the limit of $max punches for today. Please contact HR if you need a correction.");
                    }
                }
                if ($photo instanceof UploadedFile) {
                    $photoPath = PunchPhoto::store($photo, $employee->tenant_id);
                }

                $metadata += array_filter([
                    'payload_hash' => $fingerprint, 'derived_direction' => $derived ?: null, 'timezone_source' => $zoneSource,
                    'geofence' => $geo['status'] === 'not_required' && ! $geo['exempt'] ? null : array_filter(['mode' => $geo['mode'], 'distance_meters' => $geo['distance_meters'], 'exempt' => $geo['exempt']], fn ($v) => $v !== null),
                ], fn ($v) => $v !== null);

                $event = AttendanceEvent::query()->create([
                    'employee_id' => $employee->id, 'punched_at' => $at, 'direction' => $direction, 'source' => $source, 'device_id' => $device?->id,
                    'location_id' => $in['location_id'] ?? $device?->location_id, 'timezone' => $zone, 'local_date' => $localDate,
                    'latitude' => $lat, 'longitude' => $lng, 'accuracy_meters' => $accuracy === null ? null : (int) round($accuracy),
                    'geofence_status' => $geo['status'], 'geofence_id' => $geo['geofence_id'],
                    'ip_address' => $live || $source === 'admin' ? ($in['ip'] ?? null) : null,
                    'device_identifier' => $device?->device_identifier ?? (isset($in['client_device']) ? substr((string) $in['client_device'], 0, 120) : null),
                    'external_ref' => $ref, 'idempotency_key' => $key, 'import_id' => $in['import_id'] ?? null, 'imported_at' => $source === 'import' ? now() : null,
                    'raw_metadata' => $metadata ?: null, 'verification_status' => $photoPath || $requirePhoto ? 'pending' : 'not_required', 'photo_path' => $photoPath,
                    'processing_state' => 'pending', 'created_by' => $source === 'admin' ? $actor?->id : null,
                ]);

                return new PunchResult($event, false, null, $geo);
            });
        } catch (\Throwable $e) {
            if ($photoPath) {
                Storage::disk(\App\Domain\Attendance\Boot\CaptureBoot::DISK)->delete($photoPath);
            }
            throw $e;
        }
        if ($photoPath && $result->duplicate) {
            Storage::disk(\App\Domain\Attendance\Boot\CaptureBoot::DISK)->delete($photoPath);
        }
        if ($result->duplicate) {
            return $result;
        }

        $event = $result->event;
        if ($source === 'admin') {
            Audit::record('punch_added', 'attendance', 'attendance_event:'.$event->uuid, ['employee_id' => $employee->id, 'direction' => $event->direction, 'punched_at' => $event->punched_at->toIso8601String(), 'reason' => $in['reason'] ?? null]);
        }
        if (($in['emit_events'] ?? true) && in_array($event->direction, ['IN', 'OUT'], true)) {
            $this->announce($employee, $event, $zone);
        }
        if ($in['recalculate'] ?? true) {
            $this->requestRecalculation($employee->id, $this->affectedDates($employee->id, $localDate, $at), 'punch', $actor?->id);
        }

        return $result;
    }

    /** Admin: void a punch (never deletes). Reason is required; the day is recalculated. */
    public function void(AttendanceEvent $event, User $actor, string $reason): AttendanceEvent
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PunchRejected('reason_required', 'Please give a reason for voiding this punch.');
        }
        if ($event->isVoided()) {
            return $event;
        }
        if (app()->bound(LockGuard::class)) {
            app(LockGuard::class)->assertUnlocked($event->employee_id, $event->local_date->toDateString());
        }
        $event->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => mb_substr($reason, 0, 255), 'processing_state' => 'pending'])->save();
        Audit::record('punch_voided', 'attendance', 'attendance_event:'.$event->uuid, ['employee_id' => $event->employee_id, 'punched_at' => $event->punched_at->toIso8601String(), 'direction' => $event->direction, 'reason' => $reason]);
        $this->requestRecalculation($event->employee_id, $this->affectedDates($event->employee_id, $event->local_date->toDateString(), $event->punched_at), 'punch_void', $actor->id);

        return $event;
    }

    public function verify(AttendanceEvent $event, User $actor, string $status, ?string $note = null): AttendanceEvent
    {
        if (! in_array($status, ['pending', 'verified', 'failed'], true)) {
            throw new PunchRejected('invalid_status', 'Verification status must be pending, verified or failed.');
        }
        $before = $event->verification_status;
        $event->forceFill(['verification_status' => $status])->save();
        Audit::record('punch_verification_'.$status, 'attendance', 'attendance_event:'.$event->uuid, ['employee_id' => $event->employee_id, 'from' => $before, 'note' => $note]);

        return $event;
    }

    /** One batched recalculation for many punches: employee ids + date range, trigger given. Returns the run id or null when no recalculator is bound. */
    public function requestBatchRecalculation(array $employeeIds, string $from, string $to, string $trigger, ?int $requestedBy = null): ?string
    {
        if (! app()->bound(AttendanceRecalculator::class) || $employeeIds === []) {
            return null;
        }

        return app(AttendanceRecalculator::class)->recalculate(['employee_ids' => array_values(array_unique($employeeIds)), 'from' => $from, 'to' => $to], $trigger, $requestedBy);
    }

    public function requestRecalculation(int $employeeId, array $dates, string $trigger, ?int $requestedBy = null): void
    {
        if (! app()->bound(AttendanceRecalculator::class)) {
            return;
        }
        DB::afterCommit(function () use ($employeeId, $dates, $trigger, $requestedBy) {
            foreach ($dates as $date) {
                app(AttendanceRecalculator::class)->recalculateDay($employeeId, $date, $trigger, $requestedBy);
            }
        });
    }

    /** The punch's own local date, plus the previous date when it falls inside that day's (night) shift punch window. */
    private function affectedDates(int $employeeId, string $localDate, CarbonImmutable $at): array
    {
        $dates = [$localDate];
        if (app()->bound(ScheduleResolver::class)) {
            try {
                $previous = CarbonImmutable::parse($localDate)->subDay()->toDateString();
                $schedule = app(ScheduleResolver::class)->resolve($employeeId, $previous);
                if ($schedule->isWorkingDay() && $schedule->windowEnd && $at->lte($schedule->windowEnd)) {
                    $dates[] = $previous;
                }
            } catch (\Throwable) {
                // scheduling is advisory here; the nightly run covers any gap
            }
        }

        return $dates;
    }

    private function announce(Employee $employee, AttendanceEvent $event, string $zone): void
    {
        $local = TimeZones::utcToLocal($event->punched_at, $zone)->format('H:i');
        $in = $event->direction === 'IN';
        app(DomainEvents::class)->record($in ? 'EmployeeCheckedIn' : 'EmployeeCheckedOut', [
            'employee' => $employee, 'summary' => ($in ? 'Checked in at ' : 'Checked out at ').$local.' ('.$zone.')',
            'payload' => ['event_uuid' => $event->uuid, 'source' => $event->source, 'local_date' => $event->local_date->toDateString(), 'geofence_status' => $event->geofence_status],
            'subject' => ['attendance_event', $event->id], 'effective_date' => $event->local_date->toDateString(), 'actor_id' => auth()->id(),
        ]);
    }

    public function assertEmployed(Employee $employee, string $source, string $localDate): void
    {
        $fresh = Employee::query()->find($employee->id);
        if (! $fresh) {
            throw new PunchRejected('not_employed', 'This employee record was not found.', 404);
        }
        $joined = $fresh->joined_on?->toDateString();
        $separated = $fresh->separated_on?->toDateString();
        if (in_array($fresh->status, ['preboarding', 'archived'], true) || ($joined && $localDate < $joined)) {
            throw new PunchRejected('not_employed', 'Attendance can be recorded only from the joining date. This person has not started yet.');
        }
        if (in_array($fresh->status, ['separated', 'inactive'], true) && (in_array($source, ['web', 'mobile', 'kiosk'], true) || ! $separated)) {
            throw new PunchRejected('separated', 'This person is no longer employed, so attendance cannot be recorded.');
        }
        if ($separated && $localDate > $separated) {
            throw new PunchRejected('separated', 'This person is no longer employed after '.$separated.', so attendance cannot be recorded.');
        }
    }

    /** Timezone in force: device, then punch location, then employee, then tenant. @return array{0:string,1:string} */
    public function zoneFor(Employee $employee, ?AttendanceDevice $device, ?int $locationId): array
    {
        if ($device && TimeZones::isValid($device->timezone)) {
            return [$device->timezone, 'device'];
        }
        $locationId ??= $device?->location_id;
        if ($locationId) {
            $zone = OrganizationRecord::forType('locations')->newQuery()->find($locationId)?->metadata['timezone'] ?? null;
            if (TimeZones::isValid($zone)) {
                return [$zone, 'location'];
            }
        }
        if (TimeZones::isValid($employee->timezone)) {
            return [$employee->timezone, 'employee'];
        }

        return [TenantClock::timezone(), 'tenant'];
    }

    /** @return array{0:?float,1:?float,2:?float} validated coordinates, or nulls */
    private function coordinates(array $in): array
    {
        $lat = isset($in['latitude']) && is_numeric($in['latitude']) ? (float) $in['latitude'] : null;
        $lng = isset($in['longitude']) && is_numeric($in['longitude']) ? (float) $in['longitude'] : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            return [null, null, null];
        }
        $accuracy = isset($in['accuracy']) && is_numeric($in['accuracy']) ? max(0.0, (float) $in['accuracy']) : null;

        return [round($lat, 7), round($lng, 7), $accuracy];
    }

    /** Effective policy rules for the employee on a date (defaults when no policy engine/assignment exists). */
    public function rulesFor(Employee $employee, string $date): array
    {
        if (app()->bound('attendance.punch_rules')) {
            $rules = (app('attendance.punch_rules'))($employee, $date);
            if (is_array($rules)) {
                return PolicyRules::merge($rules);
            }
        }
        $class = 'App\\Domain\\Attendance\\Policies\\PolicyResolver';
        if (class_exists($class)) {
            try {
                $resolver = app($class);
                foreach (['rulesFor', 'effectiveRules', 'resolve'] as $method) {
                    if (! method_exists($resolver, $method)) {
                        continue;
                    }
                    $out = $resolver->$method($employee->id, $date);
                    $rules = is_array($out) ? $out : (is_object($out) ? ($out->rules ?? (method_exists($out, 'rules') ? $out->rules() : null)) : null);
                    if (is_array($rules)) {
                        return PolicyRules::merge($rules);
                    }
                }
            } catch (\Throwable) {
                // fall through to defaults: a policy lookup failure must never block attendance capture
            }
        }

        return PolicyRules::DEFAULTS;
    }
}
