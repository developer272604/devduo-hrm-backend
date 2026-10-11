<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\People\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Web / mobile check-in and check-out. Server time is authoritative; the employee is always the signed-in user's own employment. */
class MyAttendanceController extends CaptureController
{
    public function status(Request $r)
    {
        $employee = $this->myEmployee($r);
        $now = CarbonImmutable::now('UTC');
        $zone = $employee->timezone ?: 'UTC';
        $today = TimeZones::localDate($now, $zone);
        $rules = $this->service()->rulesFor($employee, $today);
        $restrictions = $rules['punch_restrictions'] ?? [];

        $last = AttendanceEvent::query()->where('employee_id', $employee->id)->whereNull('voided_at')->orderByDesc('punched_at')->orderByDesc('id')->with(['geofence'])->first();
        $maxSession = (int) ($rules['pairing']['max_session_minutes'] ?? 1440);
        $status = 'none';
        $missingCheckout = false;
        if ($last && $last->direction === 'IN') {
            $missingCheckout = $last->punched_at->lt($now->subMinutes($maxSession));
            $status = $missingCheckout ? 'none' : 'IN';
        } elseif ($last && $last->direction === 'OUT') {
            $status = 'OUT';
        }

        [$worked, $firstIn] = $this->workedMinutes($employee, $today, $now, $status === 'IN' ? $last : null);
        $schedule = null;
        $expectedEnd = null;
        if (app()->bound(ScheduleResolver::class)) {
            try {
                $day = app(ScheduleResolver::class)->resolve($employee->id, $today);
                $schedule = ['date' => $day->date, 'kind' => $day->kind, 'shift' => $day->shiftCode, 'start' => $day->start?->toIso8601String(), 'end' => $day->end?->toIso8601String(), 'required_minutes' => $day->requiredMinutes,
                    'timezone' => $day->timezone, 'holiday' => $day->holidayName];
                if ($day->isWorkingDay()) {
                    $expectedEnd = $day->end ?? ($firstIn && $day->requiredMinutes ? $firstIn->addMinutes($day->requiredMinutes) : null);
                }
            } catch (\Throwable) {
                $schedule = null;
            }
        }

        $mode = AttendanceSettings::get('location.mode', 'off');
        $capture = $mode !== 'off' && ! empty($restrictions['capture_location']);
        $methods = array_values(array_intersect(AttendanceSettings::get('methods', []), $rules['methods'] ?? []));

        return response()->json(['data' => [
            'employee' => ['id' => $employee->uuid, 'employee_number' => $employee->employee_number],
            'status' => $status,
            'missing_checkout' => $missingCheckout,
            'next_direction' => $status === 'IN' ? 'OUT' : 'IN',
            'server_time' => $now->toIso8601String(),
            'timezone' => $zone,
            'local_time' => TimeZones::utcToLocal($now, $zone)->format('Y-m-d H:i:s'),
            'local_date' => $today,
            'last_punch' => $last ? $this->presenter()->present($last, $r->user()) : null,
            'worked_minutes_so_far' => $worked,
            'worked_minutes_note' => 'Display only: closed in/out pairs plus the open session until now. The official figure comes from attendance processing.',
            'expected_end' => $expectedEnd?->toIso8601String(),
            'schedule' => $schedule,
            'location' => [
                'mode' => $mode, 'required' => $capture && $mode === 'required', 'will_be_captured' => $capture, 'geofence' => $restrictions['geofence'] ?? 'anywhere', 'photo_required' => (bool) ($restrictions['require_photo'] ?? false),
                'purpose' => $capture ? AttendanceSettings::get('location.purpose') : null,
                'privacy_note' => 'Your location is read only at the moment you check in or out. It is never tracked in the background.',
            ],
            'methods' => $methods,
            'punch_enabled' => AttendanceSettings::methodEnabled('web') || AttendanceSettings::methodEnabled('mobile'),
        ]]);
    }

    public function punch(Request $r)
    {
        $data = $r->validate([
            'direction' => 'nullable|in:IN,OUT,in,out', 'idempotency_key' => 'nullable|string|max:120', 'client_time' => 'nullable|date', 'source' => 'nullable|in:web,mobile',
            'latitude' => 'nullable|numeric', 'longitude' => 'nullable|numeric', 'accuracy' => 'nullable|numeric|min:0', 'device_identifier' => 'nullable|string|max:120',
            'platform' => 'nullable|string|max:30', 'app_version' => 'nullable|string|max:30', 'photo' => 'nullable|file|max:3072',
        ]);
        $employee = $this->myEmployee($r);
        $platform = strtolower((string) ($data['platform'] ?? ''));
        $source = $data['source'] ?? (in_array($platform, ['ios', 'android'], true) ? 'mobile' : 'web');
        $key = $r->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        $result = $this->service()->record([
            'employee' => $employee, 'source' => $source, 'direction' => $data['direction'] ?? null, 'idempotency_key' => $key ? substr((string) $key, 0, 120) : null,
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'accuracy' => $data['accuracy'] ?? null, 'ip' => $r->ip(), 'client_device' => $data['device_identifier'] ?? null,
            'photo' => $r->file('photo'), 'actor' => $r->user(),
            'metadata' => array_filter(['client_time' => $data['client_time'] ?? null, 'platform' => $data['platform'] ?? null, 'app_version' => $data['app_version'] ?? null, 'user_agent' => substr((string) $r->userAgent(), 0, 200)], fn ($v) => $v !== null && $v !== ''),
        ]);
        $event = $result->event->load(['geofence', 'device']);
        $local = TimeZones::utcToLocal($event->punched_at, $event->timezone)->format('H:i');
        $message = ($event->direction === 'IN' ? 'Checked in' : ($event->direction === 'OUT' ? 'Checked out' : 'Punch recorded')).' at '.$local.'.';
        if ($result->duplicate) {
            $message = 'This punch was already recorded at '.$local.'. No second punch was added.';
        } elseif ($event->geofence_status === 'outside') {
            $message .= ' You appear to be outside the approved work area, so your manager may review it.';
        } elseif ($event->geofence_status === 'unavailable') {
            $message .= ' We could not confirm your location accurately.';
        }

        return response()->json(['data' => $this->presenter()->present($event, $r->user()), 'duplicate' => $result->duplicate, 'duplicate_reason' => $result->reason, 'message' => $message], $result->duplicate ? 200 : 201);
    }

    public function punches(Request $r)
    {
        $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $employee = $this->myEmployee($r);
        $date = $r->input('date', TimeZones::localDate(CarbonImmutable::now('UTC'), $employee->timezone ?: 'UTC'));
        $events = AttendanceEvent::query()->where('employee_id', $employee->id)->where('local_date', $date)->with(['geofence', 'device'])->orderBy('punched_at')->orderBy('id')->get();

        return response()->json(['data' => $this->presenter()->presentMany($events, $r->user()), 'date' => $date]);
    }

    /** @return array{0:int,1:?CarbonImmutable} minutes worked so far today (display only) and the first IN of the session */
    private function workedMinutes(Employee $employee, string $today, CarbonImmutable $now, ?AttendanceEvent $openIn): array
    {
        $events = AttendanceEvent::query()->where('employee_id', $employee->id)->where('local_date', $today)->whereNull('voided_at')->orderBy('punched_at')->orderBy('id')->get(['direction', 'punched_at']);
        $open = null;
        $total = 0;
        $firstIn = null;
        if ($events->isNotEmpty() && $events->first()->direction === 'OUT' && $openIn && $openIn->local_date->toDateString() < $today) {
            $open = $openIn->punched_at;
            $firstIn = $open;
        }
        foreach ($events as $e) {
            if ($e->direction === 'IN') {
                $open ??= $e->punched_at;
                $firstIn ??= $e->punched_at;
            } elseif ($e->direction === 'OUT' && $open) {
                $total += max(0, $open->diffInMinutes($e->punched_at, true));
                $open = null;
            }
        }
        if ($open) {
            $total += max(0, $open->diffInMinutes($now, true));
        }

        return [(int) floor($total), $firstIn];
    }
}
