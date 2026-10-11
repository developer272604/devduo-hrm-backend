<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Models\EmployeeAddress;
use App\Domain\People\Records\AddressService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddressController extends RecordsController
{
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'type' => [$req, Rule::in(EmployeeAddress::TYPES)], 'line1' => [$req, 'string', 'max:255'], 'line2' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:30'], 'country' => [$req, 'string', 'size:2'], 'extra' => ['nullable', 'array'],
            'is_primary' => ['sometimes', 'boolean'], 'effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function index(Request $r, string $uuid, AddressService $service)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->access()->canView($r->user(), 'address.records', $employee), 403, 'You cannot view addresses for this employee.');

        return ['data' => $service->list($employee)->map(fn ($a) => $service->present($a))->all(), 'viewer' => ['edit' => $this->access()->editGroup($r->user(), 'address', $employee)]];
    }

    public function store(Request $r, string $uuid, AddressService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'address', 'address');
        $address = $service->create($employee, $r->validate($this->rules(true)));

        return response()->json($service->present($address), 201);
    }

    public function update(Request $r, string $uuid, string $id, AddressService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'address', 'address');
        $address = $service->find($employee, $id);

        return $service->present($service->update($employee, $address, $r->validate($this->rules(false))));
    }

    public function destroy(Request $r, string $uuid, string $id, AddressService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'address', 'address');
        $service->delete($employee, $service->find($employee, $id));

        return response()->noContent();
    }
}
