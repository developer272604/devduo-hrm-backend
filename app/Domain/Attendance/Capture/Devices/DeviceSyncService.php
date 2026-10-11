<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Access\Classification;
use App\Domain\Attendance\Capture\PunchRejected;
use App\Domain\Attendance\Capture\PunchService;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Models\DeviceSyncRun;
use App\Domain\Attendance\Models\DeviceUnmappedEvent;
use App\Domain\Attendance\Models\EmployeeDeviceMapping;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Device event ingestion and sync-run lifecycle. NormalizedPunch -> PunchService::record is the only path to a punch.
 *
 * A sync run is opened by the first events request (or by attendance:sync-devices for pull providers). A push request that
 * does not say "more": true closes its own run; otherwise the run stays open until /sync-complete. Closing a run requests
 * ONE batched recalculation for every employee-date inserted during the run (trigger "device_sync") and emits
 * DeviceSyncCompleted / DeviceSyncFailed.
 */
class DeviceSyncService
{
    public const MAX_BATCH = 500;

    public function __construct(private PunchService $punches, private DeviceProviders $providers) {}

    public function openRun(AttendanceDevice $device): DeviceSyncRun
    {
        $open = DeviceSyncRun::query()->where('device_id', $device->id)->where('status', 'running')->where('started_at', '>=', now()->subHours(6))->orderByDesc('id')->first();

        return $open ?? DeviceSyncRun::query()->create(['device_id' => $device->id, 'started_at' => now(), 'status' => 'running']);
    }

    /**
     * Push ingestion of raw vendor events.
     *
     * @return array{run:DeviceSyncRun,results:array<int,array>,summary:array<string,int>}
     */
    public function ingestRaw(AttendanceDevice $device, array $events, bool $more = false): array
    {
        if (count($events) > self::MAX_BATCH) {
            throw new PunchRejected('batch_too_large', 'Send at most '.self::MAX_BATCH.' events per request.', 413);
        }
        $run = $this->openRun($device);
        $provider = $this->providers->forDevice($device);
        $results = [];
        $counts = ['inserted' => 0, 'duplicate' => 0, 'unmapped' => 0, 'error' => 0];
        foreach (array_values($events) as $i => $raw) {
            try {
                if (! is_array($raw)) {
                    throw new \InvalidArgumentException('Each event must be an object.');
                }
                $punch = $provider->normalizePayload($raw, $device);
                $result = $this->ingestPunch($device, $punch, $run);
            } catch (\InvalidArgumentException $e) {
                $result = ['status' => 'error', 'reason' => $e->getMessage(), 'event_id' => null, 'external_ref' => is_array($raw) ? ($raw['external_ref'] ?? $raw['event_id'] ?? $raw['id'] ?? null) : null];
            }
            $counts[$result['status'] === 'inserted' ? 'inserted' : $result['status']]++;
            $results[] = ['index' => $i] + $result;
        }
        $this->addCounts($run, count($events), $counts);
        if (! $more) {
            $run = $this->complete($device, $run);
        }

        return ['run' => $run, 'results' => $results, 'summary' => $counts + ['received' => count($events)]];
    }

    /** @return array{status:string,reason:?string,event_id:?string,external_ref:?string} */
    public function ingestPunch(AttendanceDevice $device, NormalizedPunch $punch, ?DeviceSyncRun $run = null): array
    {
        $base = ['reason' => null, 'event_id' => null, 'external_ref' => $punch->externalRef];
        $zone = TimeZones::isValid($device->timezone) ? $device->timezone : 'UTC';
        $date = TimeZones::localDate($punch->occurredAt, $zone);
        $employee = $this->employeeFor($device, $punch->deviceEmployeeId, $date);
        if (! $employee) {
            $exists = DeviceUnmappedEvent::query()->where('device_id', $device->id)->where('device_employee_id', $punch->deviceEmployeeId)->where('occurred_at', $punch->occurredAt->utc()->format('Y-m-d H:i:s'))->exists();
            if (! $exists) {
                DeviceUnmappedEvent::query()->create(['device_id' => $device->id, 'sync_run_id' => $run?->id, 'device_employee_id' => $punch->deviceEmployeeId, 'occurred_at' => $punch->occurredAt,
                    'direction' => $punch->direction, 'external_ref' => $punch->externalRef, 'raw' => $punch->rawMetadata]);
            }

            return ['status' => 'unmapped', 'reason' => 'No employee is mapped to device user "'.$punch->deviceEmployeeId.'" on '.$date.'. Add a mapping, then re-send the event.'] + $base;
        }
        try {
            $result = $this->punches->record([
                'employee' => $employee, 'source' => in_array($device->type, ['biometric', 'rfid', 'face'], true) ? 'biometric' : 'api', 'direction' => $punch->direction, 'at' => $punch->occurredAt,
                'device' => $device, 'external_ref' => $punch->externalRef, 'metadata' => $punch->rawMetadata + ['device_employee_id' => $punch->deviceEmployeeId], 'recalculate' => false,
            ]);
        } catch (PunchRejected $e) {
            return ['status' => 'error', 'reason' => $e->getMessage()] + $base;
        } catch (HttpException $e) {
            return ['status' => 'error', 'reason' => $e->getMessage() ?: 'The attendance period is locked.'] + $base;
        }

        return ['status' => $result->duplicate ? 'duplicate' : 'inserted', 'event_id' => $result->event->uuid] + $base;
    }

    /** Mapping effective on the punch's local date; the employee must still exist. */
    public function employeeFor(AttendanceDevice $device, string $deviceEmployeeId, string $date): ?Employee
    {
        $mapping = EmployeeDeviceMapping::query()->where('device_id', $device->id)->where('device_employee_id', $deviceEmployeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();

        return $mapping ? Employee::query()->find($mapping->employee_id) : null;
    }

    private function addCounts(DeviceSyncRun $run, int $fetched, array $counts): void
    {
        $run->forceFill(['fetched' => $run->fetched + $fetched, 'inserted' => $run->inserted + $counts['inserted'], 'duplicates' => $run->duplicates + $counts['duplicate'],
            'unmapped' => $run->unmapped + $counts['unmapped'], 'errors' => $run->errors + $counts['error']])->save();
    }

    /** Closes a run: status, device bookkeeping, ONE batched recalculation, domain event. */
    public function complete(AttendanceDevice $device, DeviceSyncRun $run, ?string $failure = null, ?string $cursor = null): DeviceSyncRun
    {
        if ($run->status !== 'running') {
            return $run;
        }
        $status = $failure ? 'failed' : ($run->errors > 0 || $run->unmapped > 0 ? 'partial' : 'completed');
        $summary = $failure;
        if (! $failure && $run->unmapped > 0) {
            $summary = "We couldn't match {$run->unmapped} event(s) to employees. Add device mappings for them on the device page.";
        }
        $run->forceFill(['status' => $status, 'finished_at' => now(), 'error_summary' => $summary ? mb_substr($summary, 0, 2000) : null, 'cursor' => $cursor ?? $run->cursor])->save();
        $device->forceFill(['last_sync_at' => now(), 'last_sync_status' => $status])->save();

        $events = app(DomainEvents::class);
        if ($status === 'failed') {
            Log::warning('attendance.device_sync_failed', ['device_id' => $device->id, 'tenant_id' => $device->tenant_id, 'run_id' => $run->id, 'error' => $failure]);
            $events->record('DeviceSyncFailed', ['summary' => 'Device sync failed for '.$device->name, 'payload' => ['device' => $device->uuid, 'run_id' => $run->id, 'error' => $failure],
                'subject' => ['attendance_device', $device->id], 'classification' => Classification::HR_CONFIDENTIAL, 'actor_id' => null]);

            return $run;
        }
        $this->recalculateRun($device, $run);
        $events->record('DeviceSyncCompleted', ['summary' => 'Device sync finished for '.$device->name.': '.$run->inserted.' new punch(es)',
            'payload' => ['device' => $device->uuid, 'run_id' => $run->id, 'status' => $status, 'fetched' => $run->fetched, 'inserted' => $run->inserted, 'duplicates' => $run->duplicates, 'unmapped' => $run->unmapped, 'errors' => $run->errors],
            'subject' => ['attendance_device', $device->id], 'classification' => Classification::HR_CONFIDENTIAL, 'actor_id' => null]);

        return $run;
    }

    private function recalculateRun(AttendanceDevice $device, DeviceSyncRun $run): void
    {
        if ($run->inserted < 1) {
            return;
        }
        $rows = AttendanceEvent::query()->where('device_id', $device->id)->where('created_at', '>=', $run->started_at->copy()->subSeconds(1))->whereNull('voided_at')->get(['employee_id', 'local_date']);
        if ($rows->isEmpty()) {
            return;
        }
        $dates = $rows->map(fn ($r) => $r->local_date->toDateString());
        $this->punches->requestBatchRecalculation($rows->pluck('employee_id')->unique()->all(), $dates->min(), $dates->max(), 'device_sync');
    }

    /** After a mapping is added: store the events that were waiting for it. Returns the number of punches inserted (ONE batched recalculation). */
    public function replayUnmapped(AttendanceDevice $device, string $deviceEmployeeId): int
    {
        $pending = DeviceUnmappedEvent::query()->where('device_id', $device->id)->where('device_employee_id', $deviceEmployeeId)->whereNull('resolved_at')->orderBy('occurred_at')->get();
        $inserted = 0;
        $employees = [];
        $dates = [];
        foreach ($pending as $row) {
            $result = $this->ingestPunch($device, new NormalizedPunch($row->device_employee_id, $row->occurred_at, $row->direction, $row->external_ref, $row->raw ?? []));
            if ($result['status'] === 'unmapped') {
                continue;
            }
            $row->forceFill(['resolved_at' => now()])->save();
            if ($result['status'] === 'inserted') {
                $inserted++;
                $event = AttendanceEvent::query()->where('uuid', $result['event_id'])->first(['employee_id', 'local_date']);
                $employees[$event->employee_id] = true;
                $dates[] = $event->local_date->toDateString();
            }
        }
        if ($inserted) {
            $this->punches->requestBatchRecalculation(array_keys($employees), min($dates), max($dates), 'device_sync');
        }

        return $inserted;
    }

    /** Pull-model sync for one device (isolated: never throws). */
    public function pull(AttendanceDevice $device): DeviceSyncRun
    {
        $run = $this->openRun($device);
        try {
            $provider = $this->providers->forDevice($device);
            $since = DeviceSyncRun::query()->where('device_id', $device->id)->whereIn('status', ['completed', 'partial'])->orderByDesc('id')->value('cursor');
            $since = $since ? CarbonImmutable::parse($since) : null;
            $latest = $since;
            $counts = ['inserted' => 0, 'duplicate' => 0, 'unmapped' => 0, 'error' => 0];
            $fetched = 0;
            foreach ($provider->fetchEvents($device, $since) as $punch) {
                $fetched++;
                $result = $this->ingestPunch($device, $punch, $run);
                $counts[$result['status'] === 'inserted' ? 'inserted' : $result['status']]++;
                $latest = $latest === null || $punch->occurredAt->gt($latest) ? $punch->occurredAt : $latest;
            }
            $this->addCounts($run, $fetched, $counts);

            return $this->complete($device, $run->refresh(), null, $latest?->toIso8601String());
        } catch (\Throwable $e) {
            return $this->complete($device, $run->refresh(), 'The device could not be reached: '.$e->getMessage());
        }
    }
}
