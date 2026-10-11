<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Domain\People\Records\PhotoService;
use Illuminate\Http\Request;

class PhotoController extends RecordsController
{
    /** Employees may always change their own photo; HR needs direct edit rights on the personal group. */
    private function writable(Request $r, string $uuid): Employee
    {
        $employee = $this->employee($r, $uuid);
        $self = $this->rec()->isSelf($r->user(), $employee);
        abort_unless($self || $this->access()->editGroup($r->user(), 'personal', $employee) === 'direct', 403, 'You cannot change this photo.');

        return $employee;
    }

    public function show(Request $r, string $uuid, PhotoService $service)
    {
        $user = $r->user();
        $employee = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class);
        $reach = $scope->canAccess($user, $employee, 'people.view') || $scope->isSelf($user, $employee);
        // Coworkers outside the viewer's scope are visible through the directory only while working.
        abort_unless($reach || ($user->allows('people.directory') && $employee->isWorking()), 404);
        abort_if($this->access()->view($user, 'person.photo', $employee) === FieldAccess::HIDDEN, 404);

        return $service->stream($employee);
    }

    public function store(Request $r, string $uuid, PhotoService $service)
    {
        $employee = $this->writable($r, $uuid);
        $r->validate(['file' => 'required|file']);
        $service->store($employee, $r->file('file'));

        return response()->json(['has_photo' => true], 201);
    }

    public function destroy(Request $r, string $uuid, PhotoService $service)
    {
        $employee = $this->writable($r, $uuid);
        $service->remove($employee);

        return response()->noContent();
    }
}
