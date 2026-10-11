<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Models\Employee;
use App\Domain\People\Sections\PersonSection;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Generic REST controller for the personal sections that live on the person (contacts, emergency contacts,
 * dependents, education, experience, certifications). Concrete controllers supply the service, the field/group
 * names used for authorization, validation rules and the response shape.
 */
abstract class PersonSectionController extends PeopleController
{
    use SectionAuthorization;

    abstract protected function service(): PersonSection;

    /** FieldRegistry key deciding who may view the section, e.g. "emergency.records". */
    abstract protected function field(): string;

    /** FieldRegistry edit group, e.g. "emergency". */
    abstract protected function group(): string;

    /** @param  Model|null  $existing  the record being updated (null on create) */
    abstract protected function rules(bool $create, ?Model $existing): array;

    /** @param  array<string,mixed>  $ctx  see context() */
    abstract protected function present(Model $row, array $ctx): array;

    protected function messages(): array
    {
        return [];
    }

    /** Hook: adjust or authorize validated input (e.g. normalize country codes). */
    protected function prepare(Request $r, Employee $employee, array $data): array
    {
        return $data;
    }

    /** Extra top-level keys of the list response. */
    protected function meta(Request $r, Employee $employee, Collection $rows): array
    {
        return [];
    }

    /** Extra keys of the list response's "viewer" block. */
    protected function viewer(Request $r, Employee $employee): array
    {
        return [];
    }

    /** Context shared by the presenter: viewer, subject, document and verifier lookups. */
    protected function context(Request $r, Employee $employee, Collection $rows): array
    {
        return [
            'user' => $r->user(), 'employee' => $employee,
            'documents' => $this->canSeeDocuments($r, $employee) ? $this->service()->documentUuids($rows->pluck('document_id')->all()) : [],
            'verifiers' => $this->verifierNames($rows),
        ];
    }

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, $this->field());
        $rows = $this->service()->list($employee);
        $ctx = $this->context($r, $employee, $rows);

        return [
            'data' => $rows->map(fn ($row) => $this->present($row, $ctx))->values(),
            'viewer' => ['edit' => $this->access()->editGroup($r->user(), $this->group(), $employee)] + $this->viewer($r, $employee),
        ] + $this->meta($r, $employee, $rows);
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, $this->field());
        $this->authorizeWrite($r, $employee, $this->group());
        $data = $this->prepare($r, $employee, $r->validate($this->rules(true, null), $this->messages()));
        $row = $this->service()->create($employee, $data);

        return response()->json($this->one($r, $employee, $row), 201);
    }

    public function update(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, $this->field());
        $this->authorizeWrite($r, $employee, $this->group());
        $existing = $this->service()->find($employee, $id);
        $data = $r->validate($this->rules(false, $existing), $this->messages());
        abort_if($data === [], 422, 'No changes were submitted.');
        $row = $this->service()->update($employee, $id, $this->prepare($r, $employee, $data));

        return $this->one($r, $employee, $row);
    }

    public function destroy(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, $this->field());
        $this->authorizeWrite($r, $employee, $this->group());
        $this->service()->delete($employee, $id);

        return ['deleted' => true];
    }

    /** Verification (education, certifications). */
    public function verify(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.verify', false);
        $this->assertView($r, $employee, $this->field());
        $input = $this->validateVerification($r);
        $row = $this->service()->verify($employee, $id, $input['status'], $input['note']);

        return $this->one($r, $employee, $row);
    }

    protected function one(Request $r, Employee $employee, Model $row): array
    {
        return $this->present($row, $this->context($r, $employee, collect([$row])));
    }
}
