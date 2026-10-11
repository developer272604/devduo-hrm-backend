<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeDocument;
use App\Domain\People\Records\DocumentService;
use App\Domain\People\Records\DocumentTypes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends RecordsController
{
    public function __construct(private DocumentService $documents) {}

    /** @return array{0:Employee,1:string} employee and visibility mode; 403 when the viewer sees none of the employee's documents */
    private function context(Request $r, string $uuid): array
    {
        $employee = $this->employee($r, $uuid);
        $mode = $this->documents->visibility($r->user(), $employee);
        abort_unless($mode, 403, 'You cannot view documents for this employee.');

        return [$employee, $mode];
    }

    private function caps(Request $r, Employee $employee, string $mode, EmployeeDocument $doc): array
    {
        $self = $this->rec()->isSelf($r->user(), $employee);
        $edit = $this->access()->editGroup($r->user(), 'document', $employee) === 'direct';

        return ['edit' => $edit && ($mode === 'all' || $doc->verification_status !== 'verified'),
            'verify' => $this->rec()->reaches($r->user(), $employee, 'people.documents.verify') && ! $self, 'delete' => $this->rec()->reaches($r->user(), $employee, 'people.documents.manage')];
    }

    public function index(Request $r, string $uuid)
    {
        [$employee, $mode] = $this->context($r, $uuid);
        $r->validate(['category' => 'nullable|string|max:40', 'verification_status' => ['nullable', Rule::in(EmployeeDocument::VERIFICATION)], 'type' => 'nullable|string|max:60']);
        $query = $this->documents->query($employee, $mode)->with(['type', 'currentVersion'])->latest('id');
        $r->filled('verification_status') && $query->where('verification_status', $r->input('verification_status'));
        $r->filled('type') && $query->whereHas('type', fn ($q) => $q->where('code', $r->input('type')));
        $r->filled('category') && $query->whereHas('type', fn ($q) => $q->where('category', $r->input('category')));

        return ['data' => $query->get()->map(fn ($d) => $this->documents->present($d, $this->caps($r, $employee, $mode, $d)))->all(),
            'viewer' => ['mode' => $mode, 'upload' => $this->access()->editGroup($r->user(), 'document', $employee)]];
    }

    public function requirements(Request $r, string $uuid, DocumentTypes $types)
    {
        [$employee] = $this->context($r, $uuid);
        $types->ensureDefaults();
        $typeId = $employee->assignmentOn()?->employee_type_id;
        $have = $this->documents->query($employee, 'all')->where('verification_status', '!=', 'rejected')->pluck('document_type_id')->all();

        return ['data' => $types->requiredFor($employee->country, $typeId)->map(fn ($t) => $types->present($t) + ['provided' => in_array($t->id, $have, true)])->all()];
    }

    public function show(Request $r, string $uuid, string $id)
    {
        [$employee, $mode] = $this->context($r, $uuid);
        $doc = $this->documents->find($employee, $mode, $id);

        return $this->documents->present($doc, $this->caps($r, $employee, $mode, $doc), true);
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'document', 'document');
        $meta = $r->validate(['file' => 'required|file', 'document_type' => 'required|string|max:60'] + $this->metaRules());
        $doc = $this->documents->upload($r->user(), $employee, $r->file('file'), $meta, $this->asEmployee($r, $employee));
        $mode = $this->documents->visibility($r->user(), $employee) ?? 'own';

        return response()->json($this->documents->present($doc, $this->caps($r, $employee, $mode, $doc), true), 201);
    }

    public function update(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'document', 'document');
        [, $mode] = $this->context($r, $uuid);
        $doc = $this->documents->find($employee, $mode, $id);
        $asEmployee = $this->asEmployee($r, $employee);
        abort_if($asEmployee && $doc->verification_status === 'verified', 403, 'A verified document can only be changed by HR.');
        $data = $r->validate($this->metaRules());
        abort_if($asEmployee && array_key_exists('employee_visible', $data), 403, 'Only HR can change who sees a document.');
        $doc = $this->documents->update($r->user(), $employee, $doc, $data, $asEmployee);

        return $this->documents->present($doc, $this->caps($r, $employee, $mode, $doc), true);
    }

    public function addVersion(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid);
        $this->rec()->requireEdit($r->user(), $employee, 'document', 'document');
        [, $mode] = $this->context($r, $uuid);
        $doc = $this->documents->find($employee, $mode, $id);
        $asEmployee = $this->asEmployee($r, $employee);
        abort_if($asEmployee && $doc->verification_status === 'verified', 403, 'A verified document can only be replaced by HR.');
        $r->validate(['file' => 'required|file']);
        $doc = $this->documents->addVersion($r->user(), $employee, $doc, $r->file('file'), $asEmployee);

        return response()->json($this->documents->present($doc, $this->caps($r, $employee, $mode, $doc), true), 201);
    }

    public function download(Request $r, string $uuid, string $id)
    {
        [$employee, $mode] = $this->context($r, $uuid);
        $r->validate(['version' => 'nullable|integer|min:1', 'inline' => 'nullable|boolean']);
        $doc = $this->documents->find($employee, $mode, $id);

        return $this->documents->download($r->user(), $employee, $doc, $r->integer('version') ?: null, $r->boolean('inline'));
    }

    public function verify(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.verify', false);
        abort_if($this->rec()->isSelf($r->user(), $employee), 403, 'You cannot verify your own documents.');
        $data = $r->validate(['status' => ['required', Rule::in(EmployeeDocument::VERIFICATION)]]);
        $doc = $this->documents->find($employee, 'all', $id);
        $doc = $this->documents->verify($r->user(), $employee, $doc, $data['status']);

        return $this->documents->present($doc, $this->caps($r, $employee, 'all', $doc), true);
    }

    public function destroy(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.manage', false);
        $this->documents->delete($r->user(), $employee, $this->documents->find($employee, 'all', $id));

        return response()->noContent();
    }

    /** True when the actor is the employee themself (not HR acting on the record). */
    private function asEmployee(Request $r, Employee $employee): bool
    {
        return $this->rec()->isSelf($r->user(), $employee) && ! $this->rec()->reaches($r->user(), $employee, 'people.documents.manage');
    }

    private function metaRules(): array
    {
        return ['title' => 'sometimes|nullable|string|max:255', 'issue_date' => 'sometimes|nullable|date_format:Y-m-d', 'expiry_date' => 'sometimes|nullable|date_format:Y-m-d',
            'reference_number' => 'sometimes|nullable|string|max:120', 'notes' => 'sometimes|nullable|string|max:2000', 'tags' => 'sometimes|nullable|array|max:20', 'tags.*' => 'string|max:40',
            'employee_visible' => 'sometimes|boolean'];
    }
}
