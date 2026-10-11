<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\Audit\Audit;
use App\Domain\People\Config\Lookups;
use App\Domain\People\Models\LookupValue;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LookupController extends PeopleController
{
    private function kind(string $kind): string
    {
        abort_unless(Lookups::known($kind), 404);

        return $kind;
    }

    private function present(LookupValue $v): array
    {
        $usage = Lookups::usage($v);

        return ['id' => $v->id, 'kind' => $v->kind, 'code' => $v->code, 'label' => $v->label, 'active' => $v->active, 'system' => $v->system, 'sort' => $v->sort,
            'metadata' => $v->metadata ?: (object) [], 'in_use' => $usage > 0, 'can_delete' => ! $v->system && $usage === 0];
    }

    private function rules(): array
    {
        return ['label' => ['sometimes', 'required', 'string', 'max:150'], 'active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer', 'between:0,65535'], 'metadata' => ['sometimes', 'nullable', 'array']];
    }

    private function checkMetadata(array $data): void
    {
        if (isset($data['metadata']) && strlen(json_encode($data['metadata'])) > 4000) {
            throw ValidationException::withMessages(['metadata' => ['Metadata is too large (4000 characters at most).']]);
        }
    }

    public function index(Request $r, string $kind)
    {
        $kind = $this->kind($kind);
        Lookups::ensureDefaults($kind);
        $q = LookupValue::query()->where('kind', $kind);
        if ($r->filled('active')) {
            $q->where('active', $r->boolean('active'));
        }

        return ['kind' => $kind, 'label' => Lookups::KINDS[$kind], 'fixed_codes' => in_array($kind, Lookups::FIXED, true),
            'data' => $q->orderBy('sort')->orderBy('id')->get()->map(fn ($v) => $this->present($v))->all()];
    }

    public function store(Request $r, string $kind)
    {
        $kind = $this->kind($kind);
        abort_if(in_array($kind, Lookups::FIXED, true), 422, 'Standard employment statuses cannot be added. You can rename them.');
        $in = $r->all();
        $in['code'] = $in['code'] ?? (isset($in['label']) && is_string($in['label']) ? substr(Str::slug($in['label'], '_'), 0, 60) : null);
        $data = Validator::make($in, ['code' => ['required', 'regex:/^[a-z][a-z0-9_]{1,59}$/', Rule::unique('lookup_values', 'code')->where('tenant_id', $r->user()->tenant_id)->where('kind', $kind)]]
            + $this->rules() + ['label' => ['required', 'string', 'max:150']],
            ['code.regex' => 'Use a code of 2 to 60 lowercase letters, digits or underscores, starting with a letter.', 'code.unique' => 'This code is already used.'])->validate();
        $this->checkMetadata($data);
        Lookups::ensureDefaults($kind);

        return DB::transaction(function () use ($kind, $data) {
            $v = LookupValue::create(['kind' => $kind, 'code' => $data['code'], 'label' => $data['label'], 'active' => $data['active'] ?? true, 'system' => false,
                'sort' => $data['sort'] ?? ((int) LookupValue::query()->where('kind', $kind)->max('sort') + 10), 'metadata' => $data['metadata'] ?? null]);
            Audit::record('created', 'people', 'lookup:'.$kind.':'.$v->code, ['label' => $v->label]);

            return response()->json($this->present($v), 201);
        });
    }

    public function update(Request $r, string $kind, int $id)
    {
        $kind = $this->kind($kind);
        $v = LookupValue::query()->where('kind', $kind)->findOrFail($id);
        if ($r->has('code') && $r->input('code') !== $v->code) {
            throw ValidationException::withMessages(['code' => ['A code cannot be changed once created.']]);
        }
        $data = Validator::make($r->all(), $this->rules())->validate();
        $this->checkMetadata($data);
        if (in_array($kind, Lookups::FIXED, true) && array_key_exists('active', $data) && ! $data['active']) {
            throw ValidationException::withMessages(['active' => ['Standard employment statuses cannot be deactivated.']]);
        }

        return DB::transaction(function () use ($v, $data) {
            $before = $v->only(['label', 'active', 'sort']);
            $v->fill($data)->save();
            Audit::record('updated', 'people', 'lookup:'.$v->kind.':'.$v->code, ['before' => $before, 'after' => $v->only(array_keys($before))]);

            return $this->present($v);
        });
    }

    public function destroy(string $kind, int $id)
    {
        $v = LookupValue::query()->where('kind', $this->kind($kind))->findOrFail($id);
        abort_if($v->system, 422, 'System values cannot be deleted. Deactivate it instead.');
        abort_if(Lookups::usage($v) > 0, 422, 'This value is in use and cannot be deleted. Deactivate it instead.');
        DB::transaction(function () use ($v) {
            $v->delete();
            Audit::record('deleted', 'people', 'lookup:'.$v->kind.':'.$v->code);
        });

        return response()->noContent();
    }
}
