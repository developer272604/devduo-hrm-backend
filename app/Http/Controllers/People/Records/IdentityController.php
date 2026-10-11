<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Records\IdentityRules;
use App\Domain\People\Records\IdentityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdentityController extends RecordsController
{
    private function employeeFor(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->rec()->canView($r->user(), $employee, 'identity.records', 'people.identity.manage'), 403, 'You cannot view identity records for this employee.');

        return $employee;
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['type' => [$req, 'string', 'max:40'], 'country' => [$req, 'string', 'size:2'], 'number' => [$req, 'string', 'max:60'], 'issue_date' => 'sometimes|nullable|date_format:Y-m-d',
            'expiry_date' => 'sometimes|nullable|date_format:Y-m-d', 'document_id' => 'sometimes|nullable|uuid', 'confirm_duplicate' => 'sometimes|boolean'];
    }

    public function index(Request $r, string $uuid, IdentityService $service)
    {
        $employee = $this->employeeFor($r, $uuid);

        return ['data' => $service->list($employee)->map(fn ($i) => $service->present($i))->all(),
            'viewer' => ['edit' => $this->access()->editGroup($r->user(), 'identity', $employee), 'can_reveal' => $this->rec()->canReveal($r->user(), $employee)]];
    }

    public function store(Request $r, string $uuid, IdentityService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'identity');
        $row = $service->create($r->user(), $employee, $r->validate($this->rules(true)), $this->rec()->isHrEdit($r->user(), $employee, 'people.identity.manage'));

        return response()->json($service->present($row), 201);
    }

    public function update(Request $r, string $uuid, string $id, IdentityService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'identity');
        $row = $service->find($employee, $id);
        $row = $service->update($r->user(), $employee, $row, $r->validate($this->rules(false)), $this->rec()->isHrEdit($r->user(), $employee, 'people.identity.manage'));

        return $service->present($row);
    }

    public function destroy(Request $r, string $uuid, string $id, IdentityService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'identity');
        $service->delete($r->user(), $employee, $service->find($employee, $id));

        return response()->noContent();
    }

    public function verify(Request $r, string $uuid, string $id, IdentityService $service)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.verify', false);
        abort_if($this->rec()->isSelf($r->user(), $employee), 403, 'You cannot verify your own records.');
        $data = $r->validate(['status' => ['required', Rule::in(IdentityRules::VERIFICATION)]]);

        return $service->present($service->verify($r->user(), $employee, $service->find($employee, $id), $data['status']));
    }

    public function reveal(Request $r, string $uuid, string $id, IdentityService $service)
    {
        $employee = $this->employeeFor($r, $uuid);
        abort_unless($this->rec()->canReveal($r->user(), $employee), 403, 'You are not allowed to reveal this number.');
        $row = $service->find($employee, $id);

        return response()->json(['uuid' => $row->uuid, 'type' => $row->type, 'number' => $service->reveal($r->user(), $employee, $row)])->header('Cache-Control', 'no-store');
    }
}
