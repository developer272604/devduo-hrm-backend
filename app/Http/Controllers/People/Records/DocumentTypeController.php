<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\DocumentType;
use App\Domain\People\Models\EmployeeDocument;
use App\Domain\People\Records\DocumentTypes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends RecordsController
{
    public function index(Request $r, DocumentTypes $types)
    {
        $types->ensureDefaults();
        $manage = $r->user()->allows('people.settings');
        $rows = DocumentType::query()->when(! $manage, fn ($q) => $q->where('active', true))->orderBy('category')->orderBy('name')->get();

        return ['data' => $rows->map(fn ($t) => $types->present($t))->all()];
    }

    public function store(Request $r, DocumentTypes $types)
    {
        $data = $r->validate(
            ['code' => ['required', 'regex:/^[a-z][a-z0-9_]{1,59}$/', Rule::unique('document_types', 'code')->where('tenant_id', $r->user()->tenant_id)]] + $this->rules(true),
            ['code.unique' => 'A document type with this code already exists.', 'code.regex' => 'Use lower-case letters, digits and underscores for the code.']
        );
        $type = DocumentType::query()->create($this->clean($data) + ['active' => true]);
        Audit::record('created', 'people', 'document_type:'.$type->code, []);

        return response()->json($types->present($type), 201);
    }

    public function update(Request $r, string $code, DocumentTypes $types)
    {
        $type = DocumentType::query()->where('code', $code)->firstOrFail();
        $type->fill($this->clean($r->validate($this->rules(false))))->save();
        Audit::record('updated', 'people', 'document_type:'.$type->code, []);

        return $types->present($type);
    }

    /** Only unused custom types can be removed; everything else is deactivated (active=false). */
    public function destroy(string $code)
    {
        $type = DocumentType::query()->where('code', $code)->firstOrFail();
        abort_if(isset(DocumentTypes::DEFAULTS[$type->code]), 422, 'Built-in document types cannot be deleted. Deactivate it instead.');
        abort_if(EmployeeDocument::query()->withTrashed()->where('document_type_id', $type->id)->exists(), 422, 'This type is in use. Deactivate it instead.');
        $type->delete();
        Audit::record('deleted', 'people', 'document_type:'.$code, []);

        return response()->noContent();
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:255'], 'category' => [$req, Rule::in(DocumentType::CATEGORIES)], 'requires_expiry' => ['sometimes', 'boolean'], 'mandatory' => ['sometimes', 'boolean'],
            'mandatory_for' => ['sometimes', 'nullable', 'array'], 'mandatory_for.countries' => ['sometimes', 'array', 'max:100'], 'mandatory_for.countries.*' => ['string', 'size:2'],
            'mandatory_for.employee_types' => ['sometimes', 'array', 'max:100'], 'mandatory_for.employee_types.*' => ['integer'],
            'classification' => ['sometimes', Rule::in(array_diff(Classification::ALL, [Classification::SYSTEM_RESTRICTED]))], 'active' => ['sometimes', 'boolean'],
        ];
    }

    private function clean(array $data): array
    {
        if (isset($data['mandatory_for'])) {
            $data['mandatory_for'] = ['countries' => array_values(array_map('strtoupper', $data['mandatory_for']['countries'] ?? [])), 'employee_types' => array_values(array_map('intval', $data['mandatory_for']['employee_types'] ?? []))];
        }

        return $data;
    }
}
