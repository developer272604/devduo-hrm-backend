<?php

namespace App\Http\Controllers\Leave\Policies;

use App\Domain\Leave\Cycles\CycleService;
use App\Domain\Leave\Models\LeaveCycle;
use App\Domain\Leave\Models\LeaveCyclePeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CycleController extends Controller
{
    public function __construct(private readonly CycleService $service) {}

    private function present(LeaveCycle $c): array
    {
        return ['uuid' => $c->uuid, 'name' => $c->name, 'code' => $c->code, 'kind' => $c->kind, 'start_month' => (int) $c->start_month, 'start_day' => (int) $c->start_day,
            'length_months' => (int) $c->length_months, 'config' => $c->config, 'active' => (bool) $c->active, 'locked' => $this->service->isUsed($c)];
    }

    private function period(LeaveCyclePeriod $p): array
    {
        return ['id' => $p->id, 'label' => $p->label, 'start_date' => $p->start_date, 'end_date' => $p->end_date, 'status' => $p->status];
    }

    private function rules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return ['name' => "$s|string|max:255", 'code' => ($create ? 'required' : 'prohibited').'|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'kind' => 'sometimes|string', 'start_month' => 'sometimes|integer', 'start_day' => 'sometimes|integer',
            'length_months' => 'sometimes|integer', 'config' => 'nullable|array', 'active' => 'sometimes|boolean'];
    }

    public function index()
    {
        return ['data' => LeaveCycle::query()->orderBy('name')->get()->map(fn ($c) => $this->present($c))];
    }

    public function store(Request $r)
    {
        return response()->json(['data' => $this->present($this->service->create($r->validate($this->rules(true))))], 201);
    }

    public function show(string $uuid)
    {
        return ['data' => $this->present(LeaveCycle::byUuid($uuid))];
    }

    public function update(Request $r, string $uuid)
    {
        return ['data' => $this->present($this->service->update(LeaveCycle::byUuid($uuid), $r->validate($this->rules(false))))];
    }

    public function periods(string $uuid)
    {
        $c = LeaveCycle::byUuid($uuid);

        return ['data' => LeaveCyclePeriod::query()->where('cycle_id', $c->id)->orderBy('start_date')->get()->map(fn ($p) => $this->period($p))];
    }

    public function generate(Request $r, string $uuid)
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);
        $res = $this->service->generate(LeaveCycle::byUuid($uuid), $d['from'], $d['to']);

        return ['data' => ['created' => $res['created'], 'existing' => $res['existing'], 'periods' => array_map(fn ($p) => $this->period($p), $res['periods'])]];
    }

    /** The period containing a date; anniversary cycles take the employee's joining date (or an explicit `anchor`). */
    public function periodFor(Request $r, string $uuid)
    {
        $d = $r->validate(['date' => 'required|date_format:Y-m-d', 'employee' => 'nullable|string', 'anchor' => 'nullable|date_format:Y-m-d']);
        $c = LeaveCycle::byUuid($uuid);
        $anchor = $d['anchor'] ?? null;
        if (! empty($d['employee'])) {
            $anchor = PolicyController::scopedEmployee($r, $d['employee'])->joined_on;
            $anchor = is_string($anchor) ? substr($anchor, 0, 10) : $anchor->toDateString();
        }
        if ($c->kind === 'anniversary' && ! $anchor) {
            abort(422, 'An anniversary cycle needs an employee or an anchor date.');
        }

        return ['data' => ['cycle' => $c->code, 'date' => $d['date']] + $this->service->periodFor($c, $d['date'], $anchor)];
    }
}
