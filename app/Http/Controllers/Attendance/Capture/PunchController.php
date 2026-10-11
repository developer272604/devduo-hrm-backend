<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Boot\CaptureBoot;
use App\Domain\Attendance\Capture\Devices\GenericApiProvider;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Admin / manager views over raw punches, manual entries, voiding and photo verification. Voiding never deletes. */
class PunchController extends CaptureController
{
    public function index(Request $r)
    {
        $data = $r->validate([
            'employee_id' => 'nullable|uuid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'source' => 'nullable|in:'.implode(',', AttendanceEvent::SOURCES),
            'device_id' => 'nullable|uuid', 'direction' => 'nullable|in:IN,OUT,UNKNOWN', 'geofence_status' => 'nullable|in:inside,outside,not_checked,not_required,unavailable',
            'verification_status' => 'nullable|in:not_required,pending,verified,failed', 'voided' => 'nullable|in:0,1,only', 'per_page' => 'nullable|integer',
        ]);
        $user = $r->user();
        $permission = $user->allows('attendance.manage') ? 'attendance.manage' : 'attendance.team.view';
        $query = AttendanceEvent::query()->with(['employee.person', 'device', 'geofence']);
        app(DataScope::class)->constrain($query, $user, $permission, 'attendance_events.employee_id');
        if (! empty($data['employee_id'])) {
            $query->where('employee_id', $this->scopedEmployee($r, $data['employee_id'], $permission)->id);
        }
        if (! empty($data['device_id'])) {
            $query->where('device_id', AttendanceDevice::query()->where('uuid', $data['device_id'])->value('id') ?? 0);
        }
        foreach (['source', 'direction', 'geofence_status', 'verification_status'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        isset($data['from']) && $query->where('local_date', '>=', $data['from']);
        isset($data['to']) && $query->where('local_date', '<=', $data['to']);
        $voided = $data['voided'] ?? '0';
        $voided === '0' ? $query->whereNull('voided_at') : ($voided === 'only' ? $query->whereNotNull('voided_at') : null);
        $page = $query->orderByDesc('punched_at')->orderByDesc('id')->paginate($this->perPage($r, 50));

        return response()->json($this->page($page, $this->presenter()->presentMany($page->items(), $user)));
    }

    /** Manual entry by an administrator (reason required, audited, recalculated; locked days are refused with 423). */
    public function store(Request $r)
    {
        $data = $r->validate(['employee_id' => 'required|uuid', 'direction' => 'required|in:IN,OUT,UNKNOWN', 'punched_at' => 'required|string|max:40', 'reason' => 'required|string|min:3|max:255']);
        $employee = $this->scopedEmployee($r, $data['employee_id'], 'attendance.adjust');
        $at = GenericApiProvider::parseInstant($data['punched_at'], TimeZones::isValid($employee->timezone) ? $employee->timezone : 'UTC');
        $result = $this->service()->record(['employee' => $employee, 'source' => 'admin', 'direction' => $data['direction'], 'at' => $at, 'actor' => $r->user(), 'reason' => $data['reason'], 'ip' => $r->ip(),
            'metadata' => ['reason' => $data['reason']], 'emit_events' => false]);

        return response()->json(['data' => $this->presenter()->present($result->event->load(['employee.person', 'device', 'geofence']), $r->user()), 'duplicate' => $result->duplicate], $result->duplicate ? 200 : 201);
    }

    public function void(Request $r, string $uuid)
    {
        $data = $r->validate(['reason' => 'required|string|min:3|max:255']);
        $event = $this->event($r, $uuid, 'attendance.adjust');
        $this->service()->void($event, $r->user(), $data['reason']);

        return response()->json(['data' => $this->presenter()->present($event->load(['employee.person', 'device', 'geofence']), $r->user()), 'message' => 'The punch was voided. It stays in the history and attendance will be recalculated.']);
    }

    public function verify(Request $r, string $uuid)
    {
        $data = $r->validate(['status' => 'required|in:pending,verified,failed', 'note' => 'nullable|string|max:500']);
        $event = $this->event($r, $uuid, 'attendance.adjust');
        $this->service()->verify($event, $r->user(), $data['status'], $data['note'] ?? null);

        return response()->json(['data' => $this->presenter()->present($event->load(['employee.person', 'device', 'geofence']), $r->user())]);
    }

    /** Verification photo (private storage). Requires adjust or location-view rights over the employee; every view is audited. */
    public function photo(Request $r, string $uuid)
    {
        $event = AttendanceEvent::query()->where('uuid', $uuid)->firstOrFail();
        $user = $r->user();
        $scope = app(DataScope::class);
        $allowed = $scope->isSelf($user, $event->employee_id)
            || (($user->allows('attendance.adjust') && $scope->canAccess($user, $event->employee_id, 'attendance.adjust')) || ($user->allows('attendance.location.view') && $scope->canAccess($user, $event->employee_id, 'attendance.location.view')));
        abort_unless($allowed && $event->photo_path, 404);
        if (! $scope->isSelf($user, $event->employee_id)) {
            Audit::record('punch_photo_viewed', 'attendance', 'attendance_event:'.$event->uuid, ['employee_id' => $event->employee_id]);
        }

        return Storage::disk(CaptureBoot::DISK)->response($event->photo_path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    private function event(Request $r, string $uuid, string $permission): AttendanceEvent
    {
        $event = AttendanceEvent::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $event->employee_id, $permission), 404);

        return $event;
    }
}
