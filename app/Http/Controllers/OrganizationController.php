<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $r, string $type)
    {
        $q = OrganizationRecord::forType($type)->newQuery();
        if ($r->filled('search')) {
            $q->where('name', 'like', '%'.substr($r->search, 0, 100).'%');
        }
        if ($r->filled('active')) {
            $q->where('active', $r->boolean('active'));
        }

        return $q->orderBy('name')->paginate(20);
    }

    public function save(Request $r, string $type, ?int $id = null)
    {
        $model = OrganizationRecord::forType($type);
        $record = $id ? $model->newQuery()->findOrFail($id) : $model;
        $data = $r->validate(['name' => 'required|string|max:150', 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique($model->getTable(), 'code')->where('tenant_id', $r->user()->tenant_id)->ignore($id)], 'description' => 'nullable|string|max:2000', 'active' => 'required|boolean']);

        return DB::transaction(function () use ($record, $data, $id, $type) {
            $record->fill($data)->save();
            Audit::record($id ? 'updated' : 'created', 'organization', $type.':'.$record->id);

            return response()->json($record, $id ? 200 : 201);
        });
    }

    public function destroy(Request $r, string $type, int $id)
    {
        $record = OrganizationRecord::forType($type)->newQuery()->findOrFail($id);
        DB::transaction(function () use ($record, $type, $id) {
            $record->delete();
            Audit::record('deleted', 'organization', $type.':'.$id);
        });

        return response()->noContent();
    }
}
