<?php

namespace App\Http\Controllers\People\Lifecycle;

use App\Domain\People\Lifecycle\SeparationService;
use App\Domain\People\Models\SeparationRecord;
use App\Domain\People\Services\EmployeeRules;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Separation, rehire and retention endpoints. Nothing here deletes records. */
class SeparationController extends PeopleController
{
    public function __construct(private SeparationService $service) {}

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.separate', false);

        return ['data' => SeparationRecord::query()->where('employee_id', $employee->id)->orderByDesc('id')->get()->map(fn ($s) => $this->service->present($s))->values()];
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.separate', false);
        $data = $r->validate([
            'type' => ['required', Rule::in(SeparationService::TYPES)], 'last_working_date' => ['required', 'date_format:Y-m-d'], 'reason_code' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:2000'], 'rehire_eligible' => ['nullable', 'boolean'], 'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->service->present($this->service->initiate($r->user(), $employee, $data)), 201);
    }

    public function complete(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.separate', false);
        $data = $r->validate(['rehire_eligible' => ['nullable', 'boolean'], 'comments' => ['nullable', 'string', 'max:2000']]);

        return $this->service->present($this->service->complete($r->user(), $employee, $data));
    }

    public function withdraw(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.separate', false);
        $data = $r->validate(['comments' => ['nullable', 'string', 'max:2000']]);

        return $this->service->present($this->service->withdraw($r->user(), $employee, $data['comments'] ?? null));
    }

    public function rehire(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.rehire', false);
        $data = $r->validate(
            ['employment' => 'required|array', 'assignment' => 'sometimes|array', 'manager_id' => ['nullable', 'integer'], 'override_reason' => ['nullable', 'string', 'max:1000']]
            + EmployeeRules::prefixed('employment', EmployeeRules::employment()) + EmployeeRules::prefixed('assignment', EmployeeRules::assignment())
        );
        $new = $this->service->rehire($r->user(), $employee, $data);

        return response()->json(['uuid' => $new->uuid, 'employee_number' => $new->employee_number, 'status' => $new->status, 'employment_sequence' => $new->employment_sequence], 201);
    }

    public function retention(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.separate', false)->loadMissing('person');

        return $this->retentionBody($employee->person);
    }

    public function setRetention(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.settings', false);
        $data = $r->validate(['legal_hold' => ['sometimes', 'boolean'], 'retention_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:1000']]);
        abort_if(! array_key_exists('legal_hold', $data) && ! array_key_exists('retention_until', $data), 422, 'No changes were submitted.');

        return $this->retentionBody($this->service->setRetention($r->user(), $employee->person, $data));
    }

    public function anonymize(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.settings', false);
        $data = $r->validate(['confirm' => ['required', 'accepted'], 'reason' => ['required', 'string', 'max:1000']]);

        return $this->retentionBody($this->service->anonymize($r->user(), $employee->person, $data['reason']));
    }

    private function retentionBody($person): array
    {
        return ['legal_hold' => (bool) $person->legal_hold, 'retention_until' => $person->retention_until?->toDateString(), 'anonymized_at' => $person->anonymized_at?->toIso8601String()];
    }
}
