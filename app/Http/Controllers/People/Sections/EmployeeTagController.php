<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Sections\TagService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

/** /employees/{uuid}/tags[/{tagId}]: assign and remove catalog tags on an employment. */
class EmployeeTagController extends PeopleController
{
    public function __construct(private TagService $tags) {}

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);

        return ['data' => $this->tags->forEmployee($employee, $r->user())];
    }

    public function assign(Request $r, string $uuid, int $tagId)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $created = $this->tags->assign($employee, $tagId, $r->user());

        return response()->json(['data' => $this->tags->forEmployee($employee, $r->user())], $created ? 201 : 200);
    }

    public function unassign(Request $r, string $uuid, int $tagId)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $this->tags->unassign($employee, $tagId, $r->user());

        return ['data' => $this->tags->forEmployee($employee, $r->user())];
    }
}
