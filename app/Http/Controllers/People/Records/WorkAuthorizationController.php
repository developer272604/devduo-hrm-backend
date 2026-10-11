<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Models\WorkAuthorization;
use App\Domain\People\Records\IdentityRules;
use App\Domain\People\Records\WorkAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkAuthorizationController extends RecordsController
{
    private function employeeFor(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->rec()->canView($r->user(), $employee, 'work_authorization.records', 'people.identity.manage'), 403, 'You cannot view work authorizations for this employee.');

        return $employee;
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['type' => [$req, 'string', 'max:40'], 'country' => [$req, 'string', 'size:2'], 'number' => 'sometimes|nullable|string|max:60', 'sponsor' => 'sometimes|nullable|string|max:255',
            'issue_date' => 'sometimes|nullable|date_format:Y-m-d', 'expiry_date' => 'sometimes|nullable|date_format:Y-m-d', 'status' => ['sometimes', Rule::in(WorkAuthorization::STATUSES)],
            'document_id' => 'sometimes|nullable|uuid'];
    }

    public function index(Request $r, string $uuid, WorkAuthorizationService $service)
    {
        $employee = $this->employeeFor($r, $uuid);

        return ['data' => $service->list($employee)->map(fn ($i) => $service->present($i))->all(),
            'viewer' => ['edit' => $this->access()->editGroup($r->user(), 'identity', $employee), 'can_reveal' => $this->rec()->canReveal($r->user(), $employee)]];
    }

    public function store(Request $r, string $uuid, WorkAuthorizationService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'work_authorization');

        return response()->json($service->present($service->create($r->user(), $employee, $r->validate($this->rules(true)))), 201);
    }

    public function update(Request $r, string $uuid, string $id, WorkAuthorizationService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'work_authorization');

        return $service->present($service->update($r->user(), $employee, $service->find($employee, $id), $r->validate($this->rules(false))));
    }

    public function destroy(Request $r, string $uuid, string $id, WorkAuthorizationService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'identity', 'work_authorization');
        $service->delete($r->user(), $employee, $service->find($employee, $id));

        return response()->noContent();
    }

    public function verify(Request $r, string $uuid, string $id, WorkAuthorizationService $service)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.verify', false);
        abort_if($this->rec()->isSelf($r->user(), $employee), 403, 'You cannot verify your own records.');
        $data = $r->validate(['status' => ['required', Rule::in(IdentityRules::VERIFICATION)]]);

        return $service->present($service->verify($r->user(), $employee, $service->find($employee, $id), $data['status']));
    }

    public function reveal(Request $r, string $uuid, string $id, WorkAuthorizationService $service)
    {
        $employee = $this->employeeFor($r, $uuid);
        abort_unless($this->rec()->canReveal($r->user(), $employee), 403, 'You are not allowed to reveal this number.');
        $row = $service->find($employee, $id);

        return response()->json(['uuid' => $row->uuid, 'type' => $row->type, 'number' => $service->reveal($r->user(), $employee, $row)])->header('Cache-Control', 'no-store');
    }
}
