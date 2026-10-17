<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\Impact\ImpactService;
use Illuminate\Http\Request;

/** Business Impact Intelligence: define business results, enter or import them, read them against workforce cost. */
final class ImpactController
{
    public function __construct(private ImpactService $svc) {}

    public function metrics()
    {
        return ['items' => $this->svc->metrics(), 'kinds' => ImpactService::KINDS];
    }

    public function create(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'], 'name' => 'required|string|max:120', 'unit' => 'required|string|max:40',
            'kind' => 'required|in:'.implode(',', ImpactService::KINDS), 'currency' => 'nullable|string|size:3', 'description' => 'nullable|string|max:500']);

        return response()->json($this->svc->present($this->svc->create($r->user(), $d)), 201);
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['name' => 'sometimes|string|max:120', 'unit' => 'sometimes|string|max:40', 'description' => 'nullable|string|max:500', 'active' => 'sometimes|boolean']);

        return $this->svc->present($this->svc->update($r->user(), $this->svc->find($uuid), $d));
    }

    public function saveValues(Request $r, string $uuid)
    {
        $d = $r->validate(['values' => 'required|array|min:1|max:60', 'values.*.period' => 'required|string|size:7', 'values.*.value' => 'required|numeric|min:0|max:1000000000000', 'values.*.note' => 'nullable|string|max:300']);
        $m = $this->svc->find($uuid);

        return ['saved' => $this->svc->saveValues($r->user(), $m, $d['values'])] + ['metric' => $this->svc->present($m)];
    }

    public function deleteValue(Request $r, string $uuid, string $period)
    {
        $this->svc->deleteValue($r->user(), $this->svc->find($uuid), $period);

        return response()->noContent();
    }

    public function import(Request $r)
    {
        $d = $r->validate(['csv' => 'required|string|max:200000', 'commit' => 'sometimes|boolean']);

        return $this->svc->import($r->user(), $d['csv'], (bool) ($d['commit'] ?? false));
    }

    public function report(Request $r)
    {
        $d = $r->validate(['from' => 'required|string|size:7', 'to' => 'required|string|size:7']);

        return $this->svc->report($r->user(), $d['from'], $d['to']);
    }
}
