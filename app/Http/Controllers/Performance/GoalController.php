<?php

namespace App\Http\Controllers\Performance;

use App\Domain\Performance\{GoalService, PerformanceAccess};
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Cycle;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GoalController
{
    public function __construct(private GoalService $goals, private PerformanceAccess $access) {}

    private function find(Request $r, string $uuid): Goal
    {
        return $this->access->visibleGoals($r->user())->where('uuid', $uuid)->firstOrFail();
    }

    public function index(Request $r)
    {
        $q = $this->access->visibleGoals($r->user())->orderBy('level')->orderByDesc('id');
        foreach (['level', 'status', 'kind'] as $f) if ($r->filled($f)) $q->where($f, $r->query($f));
        if ($r->filled('owner_uuid')) $q->whereIn('owner_employee_id', Employee::where('uuid', $r->query('owner_uuid'))->select('id'));
        if ($r->boolean('mine')) $q->whereIn('owner_employee_id', $this->access->own($r->user()) ?: [0]);
        if ($r->filled('cycle_uuid')) $q->whereIn('cycle_id', Cycle::where('uuid', $r->query('cycle_uuid'))->select('id'));

        return $q->paginate(min(100, (int) $r->query('per_page', 25)))->through(fn ($g) => $this->goals->present($g));
    }

    public function show(Request $r, string $uuid)
    {
        return $this->goals->present($this->find($r, $uuid), true);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'level' => ['required', Rule::in(array_keys(GoalService::LEVELS))], 'kind' => ['nullable', Rule::in(['goal', 'objective', 'kpi'])], 'title' => 'required|string|max:200', 'description' => 'nullable|string|max:5000',
            'owner_uuid' => 'nullable|uuid', 'parent_uuid' => 'nullable|uuid', 'cycle_uuid' => 'nullable|uuid', 'department_id' => 'nullable|integer', 'weight' => 'nullable|numeric|min:0|max:100',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'visibility' => ['nullable', Rule::in(['owner', 'team', 'company'])],
            'progress_mode' => ['nullable', Rule::in(['rollup', 'manual', 'metric'])], 'unit' => 'nullable|string|max:24', 'direction' => ['nullable', Rule::in(['increase', 'decrease'])],
            'start_value' => 'nullable|numeric', 'target_value' => 'nullable|numeric', 'current_value' => 'nullable|numeric',
        ]);
        if (! empty($d['department_id'])) \App\Domain\Organization\OrganizationRecord::forType('departments')->newQuery()->whereKey($d['department_id'])->firstOrFail();
        $owner = ! empty($d['owner_uuid']) ? $this->access->employeeByUuid($d['owner_uuid']) : ($d['level'] === 'individual' ? ($this->access->own($r->user())[0] ?? null ? Employee::find($this->access->own($r->user())[0]) : null) : null);

        return response()->json($this->goals->present($this->goals->create($r->user(), $d, $owner), true), 201);
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['title' => 'sometimes|string|max:200', 'description' => 'nullable|string|max:5000', 'weight' => 'sometimes|numeric|min:0|max:100', 'start_date' => 'sometimes|date_format:Y-m-d', 'end_date' => 'sometimes|date_format:Y-m-d',
            'visibility' => ['sometimes', Rule::in(['owner', 'team', 'company'])], 'unit' => 'nullable|string|max:24', 'target_value' => 'sometimes|numeric', 'start_value' => 'sometimes|numeric']);

        return $this->goals->present($this->goals->update($r->user(), $this->find($r, $uuid), $d), true);
    }

    public function submit(Request $r, string $uuid)
    {
        return $this->goals->present($this->goals->submit($r->user(), $this->find($r, $uuid)));
    }

    public function decision(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:500']);

        return $this->goals->present($this->goals->decide($r->user(), $this->find($r, $uuid), $d['decision'], $d['note'] ?? null));
    }

    public function cascade(Request $r, string $uuid)
    {
        $d = $r->validate(['owner_uuids' => 'required|array|min:1|max:200', 'owner_uuids.*' => 'uuid', 'weight' => 'nullable|numeric|min:0|max:100']);
        $owners = collect($d['owner_uuids'])->unique()->map(fn ($u) => $this->access->employeeByUuid($u))->all();
        $created = $this->goals->cascade($r->user(), $this->find($r, $uuid), $owners, isset($d['weight']) ? (string) $d['weight'] : null);

        return response()->json(['created' => count($created), 'goals' => array_map(fn ($g) => $this->goals->present($g), $created)], 201);
    }

    public function keyResult(Request $r, string $uuid)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'weight' => 'nullable|numeric|min:0.01|max:100', 'unit' => 'nullable|string|max:24', 'direction' => ['nullable', Rule::in(['increase', 'decrease'])],
            'start_value' => 'nullable|numeric', 'target_value' => 'required|numeric', 'current_value' => 'nullable|numeric']);
        $kr = $this->goals->addKeyResult($r->user(), $this->find($r, $uuid), $d);

        return response()->json(['uuid' => $kr->uuid, 'progress' => $kr->progress], 201);
    }

    public function checkin(Request $r, string $uuid)
    {
        $d = $r->validate(['key_result_uuid' => 'nullable|uuid', 'value' => 'nullable|numeric', 'progress' => 'nullable|numeric|min:0|max:100', 'confidence' => ['nullable', Rule::in(['on_track', 'at_risk', 'off_track'])], 'note' => 'nullable|string|max:2000']);
        $g = $this->find($r, $uuid);
        $c = $this->goals->checkin($r->user(), $g, $d);

        return response()->json(['uuid' => $c->uuid, 'progress_before' => $c->progress_before, 'progress_after' => $c->progress_after, 'goal' => $this->goals->present($g->refresh())], 201);
    }

    public function complete(Request $r, string $uuid)
    {
        return $this->goals->present($this->goals->complete($r->user(), $this->find($r, $uuid)));
    }

    public function cancel(Request $r, string $uuid)
    {
        return $this->goals->present($this->goals->complete($r->user(), $this->find($r, $uuid), true));
    }

    /** Goal alignment map: ancestors up to the company goal and the visible aligned descendants. */
    public function alignment(Request $r, string $uuid)
    {
        $g = $this->find($r, $uuid); $visible = $this->access->visibleGoals($r->user());
        $up = []; $cur = $g;
        while ($cur->parent_id && count($up) < 6 && ($p = (clone $visible)->whereKey($cur->parent_id)->first())) { $up[] = $this->goals->present($p); $cur = $p; }
        $tree = function (Goal $n, int $depth) use (&$tree, $visible) {
            $row = $this->goals->present($n);
            $row['children'] = $depth >= 4 ? [] : (clone $visible)->where('parent_id', $n->id)->orderBy('id')->get()->map(fn ($c) => $tree($c, $depth + 1))->all();

            return $row;
        };

        return ['goal' => $tree($g, 0), 'ancestors' => array_reverse($up)];
    }
}
