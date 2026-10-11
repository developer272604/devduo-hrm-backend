<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Attendance\Capture\Devices\DeviceProviders;
use App\Domain\Attendance\Capture\Devices\DeviceSyncService;
use App\Domain\Attendance\Capture\Devices\DeviceTokens;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\DeviceSyncRun;
use App\Domain\Attendance\Models\DeviceUnmappedEvent;
use App\Domain\Attendance\Models\EmployeeDeviceMapping;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Device registry, tokens, connector tests, sync history and employee-device mappings. Config secrets are never returned. */
class DeviceController extends CaptureController
{
    public function providers()
    {
        $providers = app(DeviceProviders::class)->all();

        return response()->json(['data' => collect($providers)->map(fn ($p) => ['key' => $p->key(), 'label' => $p->label(), 'pulls' => $p->pulls(), 'configuration_schema' => $p->configurationSchema()])->values()]);
    }

    public function index(Request $r)
    {
        $query = AttendanceDevice::query()->orderBy('name');
        $r->filled('status') && $query->where('status', $r->input('status'));

        return response()->json(['data' => $query->get()->map(fn ($d) => $this->present($d))->values()]);
    }

    public function show(string $uuid)
    {
        return response()->json(['data' => $this->present(AttendanceDevice::byUuid($uuid))]);
    }

    public function store(Request $r)
    {
        $data = $this->validated($r);
        $device = new AttendanceDevice(collect($data)->except('config')->all());
        $device->status = $data['status'] ?? 'active';
        $device->setConfig($data['config'] ?? null);
        $device->save();
        $token = DeviceTokens::issue($device);
        Audit::record('device_created', 'attendance', 'attendance_device:'.$device->uuid, ['name' => $device->name, 'provider' => $device->provider_key, 'type' => $device->type]);

        return response()->json(['data' => $this->present($device), 'token' => $token, 'token_notice' => 'Copy this token now. It is shown only once; rotate it if it is lost.'], 201);
    }

    public function update(Request $r, string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $data = $this->validated($r, $device);
        $device->fill(collect($data)->except('config')->all());
        if (array_key_exists('config', $data)) {
            $merged = array_merge($device->config(), $data['config'] ?? []);
            $device->setConfig(array_filter($merged, fn ($v) => $v !== null && $v !== ''));
        }
        $device->save();
        Audit::record('device_updated', 'attendance', 'attendance_device:'.$device->uuid, ['name' => $device->name, 'status' => $device->status, 'config_changed' => array_key_exists('config', $data)]);

        return response()->json(['data' => $this->present($device)]);
    }

    public function rotateToken(string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $token = DeviceTokens::issue($device);
        Audit::record('device_token_rotated', 'attendance', 'attendance_device:'.$device->uuid, ['name' => $device->name]);

        return response()->json(['data' => $this->present($device), 'token' => $token, 'token_notice' => 'The previous token no longer works. Copy this one now; it is shown only once.']);
    }

    public function revokeToken(string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        DeviceTokens::revoke($device);
        Audit::record('device_token_revoked', 'attendance', 'attendance_device:'.$device->uuid, ['name' => $device->name]);

        return response()->json(['data' => $this->present($device), 'message' => 'The device can no longer connect until a new token is issued.']);
    }

    public function test(string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        try {
            $result = app(DeviceProviders::class)->forDevice($device)->testConnection($device);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'The connection test failed: '.$e->getMessage()];
        }

        return response()->json($result);
    }

    public function sync(string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        if (! app(DeviceProviders::class)->forDevice($device)->pulls()) {
            throw ValidationException::withMessages(['device' => ['This device sends its events to us, so there is nothing to pull. Check that it is online and using its token.']]);
        }
        $run = app(DeviceSyncService::class)->pull($device);

        return response()->json(['data' => $this->presentRun($run)]);
    }

    public function syncRuns(Request $r, string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $page = DeviceSyncRun::query()->where('device_id', $device->id)->orderByDesc('id')->paginate($this->perPage($r, 20));

        return response()->json($this->page($page, collect($page->items())->map(fn ($run) => $this->presentRun($run))->all()));
    }

    public function unmapped(Request $r, string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $rows = DeviceUnmappedEvent::query()->where('device_id', $device->id)->whereNull('resolved_at')->selectRaw('device_employee_id, COUNT(*) as events, MIN(occurred_at) as first_seen, MAX(occurred_at) as last_seen')
            ->groupBy('device_employee_id')->orderBy('device_employee_id')->get();

        return response()->json(['data' => $rows->map(fn ($x) => ['device_employee_id' => $x->device_employee_id, 'events' => (int) $x->events, 'first_seen' => $x->first_seen, 'last_seen' => $x->last_seen])->values(),
            'message' => $rows->isEmpty() ? null : 'Add a mapping for these device users and their waiting events will be imported automatically.']);
    }

    // ---- mappings -------------------------------------------------------------------------
    public function mappings(Request $r, string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $rows = EmployeeDeviceMapping::query()->where('device_id', $device->id)->with('employee.person')->orderBy('device_employee_id')->orderBy('effective_from')->get();

        return response()->json(['data' => $rows->map(fn ($m) => $this->presentMapping($m))->values()]);
    }

    public function storeMapping(Request $r, string $uuid)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $data = $r->validate(['employee_id' => 'required|uuid', 'device_employee_id' => 'required|string|max:80', 'effective_from' => 'nullable|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        $employee = $this->scopedEmployee($r, $data['employee_id'], 'attendance.devices.manage');
        $from = $data['effective_from'] ?? TenantClock::today();
        $this->assertNoOverlap($device, trim($data['device_employee_id']), $from, $data['effective_to'] ?? null, null);
        $mapping = EmployeeDeviceMapping::query()->create(['employee_id' => $employee->id, 'device_id' => $device->id, 'device_employee_id' => trim($data['device_employee_id']), 'effective_from' => $from, 'effective_to' => $data['effective_to'] ?? null]);
        Audit::record('device_mapping_created', 'attendance', 'attendance_device:'.$device->uuid, ['employee_id' => $employee->id, 'device_employee_id' => $mapping->device_employee_id, 'effective_from' => $from]);
        $replayed = app(DeviceSyncService::class)->replayUnmapped($device, $mapping->device_employee_id);

        return response()->json(['data' => $this->presentMapping($mapping->load('employee.person')), 'replayed_events' => $replayed], 201);
    }

    public function updateMapping(Request $r, string $uuid, int $id)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $mapping = EmployeeDeviceMapping::query()->where('device_id', $device->id)->findOrFail($id);
        $data = $r->validate(['device_employee_id' => 'sometimes|required|string|max:80', 'effective_from' => 'sometimes|required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        $deviceEmployeeId = trim($data['device_employee_id'] ?? $mapping->device_employee_id);
        $from = $data['effective_from'] ?? $mapping->effective_from->toDateString();
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $mapping->effective_to?->toDateString();
        $this->assertNoOverlap($device, $deviceEmployeeId, $from, $to, $mapping->id);
        $mapping->forceFill(['device_employee_id' => $deviceEmployeeId, 'effective_from' => $from, 'effective_to' => $to])->save();
        Audit::record('device_mapping_updated', 'attendance', 'attendance_device:'.$device->uuid, ['mapping_id' => $mapping->id, 'device_employee_id' => $deviceEmployeeId, 'effective_from' => $from, 'effective_to' => $to]);

        return response()->json(['data' => $this->presentMapping($mapping->load('employee.person'))]);
    }

    /** Ends the mapping today (history is kept); a mapping that has not started yet is removed. */
    public function endMapping(string $uuid, int $id)
    {
        $device = AttendanceDevice::byUuid($uuid);
        $mapping = EmployeeDeviceMapping::query()->where('device_id', $device->id)->findOrFail($id);
        $today = TenantClock::today();
        if ($mapping->effective_from->toDateString() > $today) {
            $mapping->delete();
        } else {
            $mapping->forceFill(['effective_to' => $mapping->effective_to && $mapping->effective_to->toDateString() < $today ? $mapping->effective_to->toDateString() : $today])->save();
        }
        Audit::record('device_mapping_ended', 'attendance', 'attendance_device:'.$device->uuid, ['mapping_id' => $id]);

        return response()->json(['message' => 'The mapping was ended.']);
    }

    private function assertNoOverlap(AttendanceDevice $device, string $deviceEmployeeId, string $from, ?string $to, ?int $ignoreId): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => ['The end date must not be before the start date.']]);
        }
        $clash = EmployeeDeviceMapping::query()->where('device_id', $device->id)->where('device_employee_id', $deviceEmployeeId)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<=', $to))->with('employee.person')->first();
        if ($clash) {
            throw ValidationException::withMessages(['device_employee_id' => ['Device user "'.$deviceEmployeeId.'" is already mapped to '.($clash->employee?->person?->display_name ?? 'another employee').' from '.$clash->effective_from->toDateString().($clash->effective_to ? ' to '.$clash->effective_to->toDateString() : '').'. End that mapping first or choose different dates.']]);
        }
    }

    private function validated(Request $r, ?AttendanceDevice $existing = null): array
    {
        $providers = array_keys(app(DeviceProviders::class)->all());
        $required = $existing ? 'sometimes|required' : 'required';
        $data = $r->validate([
            'name' => "$required|string|max:150", 'vendor' => 'nullable|string|max:60', 'type' => "$required|in:".implode(',', AttendanceDevice::TYPES),
            'provider_key' => ['sometimes', 'string', Rule::in($providers)], 'device_identifier' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'location_id' => 'nullable|integer', 'timezone' => "$required|string|max:64", 'status' => 'sometimes|in:active,inactive', 'sync_interval_minutes' => 'sometimes|integer|between:1,1440', 'config' => 'sometimes|nullable|array',
        ]);
        if (isset($data['timezone']) && ! TimeZones::isValid($data['timezone'])) {
            throw ValidationException::withMessages(['timezone' => ['Use an IANA timezone such as Asia/Kolkata.']]);
        }
        if (! empty($data['location_id']) && ! OrganizationRecord::forType('locations')->newQuery()->whereKey($data['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => ['That location was not found.']]);
        }
        if (isset($data['device_identifier'])) {
            $taken = AttendanceDevice::query()->where('device_identifier', $data['device_identifier'])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['device_identifier' => ['Another device already uses this identifier.']]);
            }
        }

        return $data;
    }

    private function present(AttendanceDevice $d): array
    {
        $provider = app(DeviceProviders::class)->forDevice($d);

        return [
            'id' => $d->uuid, 'name' => $d->name, 'vendor' => $d->vendor, 'type' => $d->type, 'provider_key' => $d->provider_key, 'provider_label' => $provider->label(), 'pulls' => $provider->pulls(),
            'device_identifier' => $d->device_identifier, 'location_id' => $d->location_id, 'timezone' => $d->timezone, 'status' => $d->status, 'sync_interval_minutes' => $d->sync_interval_minutes,
            'last_sync_at' => $d->last_sync_at?->toIso8601String(), 'last_sync_status' => $d->last_sync_status, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'has_config' => $d->config_encrypted !== null, 'has_token' => $d->auth_token_hash !== null, 'token_hint' => $d->auth_token_prefix ? 'atd_'.$d->auth_token_prefix.'…' : null,
            'token_issued_at' => $d->token_issued_at?->toIso8601String(), 'configuration_schema' => $provider->configurationSchema(),
        ];
    }

    private function presentRun(DeviceSyncRun $run): array
    {
        return ['id' => $run->id, 'status' => $run->status, 'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(), 'fetched' => $run->fetched,
            'inserted' => $run->inserted, 'duplicates' => $run->duplicates, 'unmapped' => $run->unmapped, 'errors' => $run->errors, 'message' => $run->error_summary];
    }

    private function presentMapping(EmployeeDeviceMapping $m): array
    {
        return ['id' => $m->id, 'employee' => ['id' => $m->employee?->uuid, 'employee_number' => $m->employee?->employee_number, 'name' => $m->employee?->person?->display_name], 'device_employee_id' => $m->device_employee_id,
            'effective_from' => $m->effective_from->toDateString(), 'effective_to' => $m->effective_to?->toDateString()];
    }
}
