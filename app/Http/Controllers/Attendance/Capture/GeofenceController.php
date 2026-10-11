<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Models\Geofence;
use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Geofence zones: several per location, each with a centre and radius. Evaluation rules live in GeofenceService. */
class GeofenceController extends CaptureController
{
    public function index(Request $r)
    {
        $query = Geofence::query()->orderBy('name');
        if ($r->filled('location_id')) {
            $query->where('location_id', (int) $r->input('location_id'));
        }
        if ($r->boolean('active_only')) {
            $query->where('active', true);
        }

        return response()->json(['data' => $query->get()->map(fn ($g) => $this->present($g))->values()]);
    }

    public function show(string $uuid)
    {
        return response()->json(['data' => $this->present(Geofence::byUuid($uuid))]);
    }

    public function store(Request $r)
    {
        $geofence = Geofence::query()->create($this->validated($r));
        Audit::record('geofence_created', 'attendance', 'geofence:'.$geofence->uuid, ['name' => $geofence->name, 'radius_meters' => $geofence->radius_meters]);

        return response()->json(['data' => $this->present($geofence)], 201);
    }

    public function update(Request $r, string $uuid)
    {
        $geofence = Geofence::byUuid($uuid);
        $geofence->fill($this->validated($r, true))->save();
        Audit::record('geofence_updated', 'attendance', 'geofence:'.$geofence->uuid, ['name' => $geofence->name, 'radius_meters' => $geofence->radius_meters, 'active' => $geofence->active]);

        return response()->json(['data' => $this->present($geofence)]);
    }

    public function destroy(string $uuid)
    {
        $geofence = Geofence::byUuid($uuid);
        if (AttendanceEvent::query()->where('geofence_id', $geofence->id)->exists()) {
            throw ValidationException::withMessages(['geofence' => ['Punches were already matched to this zone, so it cannot be deleted. Turn it off instead.']]);
        }
        $geofence->delete();
        Audit::record('geofence_deleted', 'attendance', 'geofence:'.$uuid, ['name' => $geofence->name]);

        return response()->json(['message' => 'The zone was deleted.']);
    }

    private function validated(Request $r, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        $data = $r->validate([
            'name' => "$required|string|max:150", 'location_id' => 'nullable|integer', 'latitude' => "$required|numeric|between:-90,90", 'longitude' => "$required|numeric|between:-180,180",
            'radius_meters' => "$required|integer|between:10,50000", 'active' => 'sometimes|boolean',
        ]);
        if (! empty($data['location_id']) && ! OrganizationRecord::forType('locations')->newQuery()->whereKey($data['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => ['That location was not found.']]);
        }

        return $data;
    }

    private function present(Geofence $g): array
    {
        return ['id' => $g->uuid, 'name' => $g->name, 'location_id' => $g->location_id, 'latitude' => $g->latitude, 'longitude' => $g->longitude, 'radius_meters' => $g->radius_meters, 'active' => $g->active];
    }
}
