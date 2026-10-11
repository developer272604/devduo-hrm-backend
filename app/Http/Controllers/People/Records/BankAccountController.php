<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Records\BankService;
use App\Domain\People\Records\RecordLog;
use Illuminate\Http\Request;

class BankAccountController extends RecordsController
{
    private function employeeFor(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->rec()->canView($r->user(), $employee, 'bank.records', 'people.bank.manage'), 403, 'You cannot view bank accounts for this employee.');

        return $employee;
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['account_holder' => [$req, 'string', 'max:255'], 'bank_name' => [$req, 'string', 'max:255'], 'country' => [$create ? 'nullable' : 'sometimes', 'string', 'size:2'],
            'currency' => 'sometimes|nullable|string|size:3', 'details' => [$req, 'array'], 'is_primary' => 'sometimes|boolean',
            'effective_from' => 'sometimes|nullable|date_format:Y-m-d', 'effective_to' => 'sometimes|nullable|date_format:Y-m-d'];
    }

    /** Every read of bank data by someone other than the employee is audited. */
    private function auditView(Request $r, $employee, int $count): void
    {
        if (! $this->rec()->isSelf($r->user(), $employee)) {
            RecordLog::audit('bank_viewed', $employee, 'bank', ['count' => $count]);
        }
    }

    public function index(Request $r, string $uuid, BankService $service)
    {
        $employee = $this->employeeFor($r, $uuid);
        $rows = $service->list($employee);
        $this->auditView($r, $employee, $rows->count());

        return ['data' => $rows->map(fn ($b) => $service->present($b))->all(),
            'viewer' => ['edit' => $this->access()->editGroup($r->user(), 'bank', $employee), 'can_reveal' => $this->rec()->canReveal($r->user(), $employee)]];
    }

    public function show(Request $r, string $uuid, string $id, BankService $service)
    {
        $employee = $this->employeeFor($r, $uuid);
        $row = $service->find($employee, $id);
        $this->auditView($r, $employee, 1);

        return $service->present($row);
    }

    public function store(Request $r, string $uuid, BankService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'bank', 'bank');

        return response()->json($service->present($service->create($r->user(), $employee, $r->validate($this->rules(true)))), 201);
    }

    public function update(Request $r, string $uuid, string $id, BankService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'bank', 'bank');

        return $service->present($service->update($r->user(), $employee, $service->find($employee, $id), $r->validate($this->rules(false))));
    }

    public function retire(Request $r, string $uuid, string $id, BankService $service)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'bank', 'bank');
        $data = $r->validate(['effective_to' => 'sometimes|nullable|date_format:Y-m-d']);

        return $service->present($service->retire($r->user(), $employee, $service->find($employee, $id), $data['effective_to'] ?? null));
    }

    public function reveal(Request $r, string $uuid, string $id, BankService $service)
    {
        $employee = $this->employeeFor($r, $uuid);
        abort_unless($this->rec()->canReveal($r->user(), $employee), 403, 'You are not allowed to reveal bank details.');
        $row = $service->find($employee, $id);

        return response()->json(['uuid' => $row->uuid, 'details' => $service->reveal($r->user(), $employee, $row)])->header('Cache-Control', 'no-store');
    }
}
