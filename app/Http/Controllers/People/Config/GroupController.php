<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\People\Config\GroupService;
use App\Domain\People\Models\EmployeeGroup;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class GroupController extends PeopleController
{
    public function __construct(private GroupService $groups) {}

    public function index(Request $r)
    {
        return $this->groups->list($r->only(['q', 'active']), $this->perPage($r));
    }

    public function show(int $id)
    {
        return $this->groups->present(EmployeeGroup::query()->findOrFail($id));
    }

    public function store(Request $r)
    {
        return response()->json($this->groups->present($this->groups->create($r->all()), 0), 201);
    }

    public function update(Request $r, int $id)
    {
        return $this->groups->present($this->groups->update(EmployeeGroup::query()->findOrFail($id), $r->all()));
    }

    public function destroy(int $id)
    {
        $this->groups->delete(EmployeeGroup::query()->findOrFail($id));

        return response()->noContent();
    }

    public function members(Request $r, int $id)
    {
        return $this->groups->members($r->user(), EmployeeGroup::query()->findOrFail($id), $this->perPage($r));
    }

    public function addMembers(Request $r, int $id)
    {
        return response()->json($this->groups->addMembers(EmployeeGroup::query()->findOrFail($id), $r->all()), 201);
    }

    public function removeMember(int $id, string $uuid)
    {
        $this->groups->removeMember(EmployeeGroup::query()->findOrFail($id), $uuid);

        return response()->noContent();
    }
}
