<?php

namespace App\Http\Controllers\People\Lifecycle;

use App\Domain\People\Lifecycle\InvitationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeInvitation;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** HR side of employee invitations (permission people.invite, data scope applies). Tokens are never returned. */
class InvitationController extends PeopleController
{
    public function __construct(private InvitationService $service) {}

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.invite', false);

        return ['data' => EmployeeInvitation::query()->where('employee_id', $employee->id)->orderByDesc('id')->limit(50)->get()->map(fn ($i) => $this->service->present($i))->values()];
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.invite', false);
        $data = $r->validate(['email' => 'nullable|email:rfc|max:255']);
        [$invitation] = $this->service->invite($r->user(), $employee, $data['email'] ?? null);

        return response()->json($this->service->present($invitation), 201);
    }

    public function resend(Request $r, string $uuid)
    {
        [$invitation] = $this->service->resend($r->user(), $this->find($r, $uuid));

        return $this->service->present($invitation);
    }

    public function cancel(Request $r, string $uuid)
    {
        return $this->service->present($this->service->cancel($r->user(), $this->find($r, $uuid)));
    }

    private function find(Request $r, string $uuid): EmployeeInvitation
    {
        abort_unless(Str::isUuid($uuid), 404);
        $invitation = EmployeeInvitation::query()->where('uuid', $uuid)->firstOrFail();
        $this->employee($r, (string) Employee::query()->whereKey($invitation->employee_id)->value('uuid'), 'people.invite', false);

        return $invitation;
    }
}
