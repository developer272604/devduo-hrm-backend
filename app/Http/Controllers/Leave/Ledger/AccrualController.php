<?php

namespace App\Http\Controllers\Leave\Ledger;

use App\Domain\Leave\Accrual\AccrualRunService;
use App\Domain\Leave\CarryForward\CarryForwardService;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Models\LeaveAccrualItem;
use App\Domain\Leave\Models\LeaveAccrualRun;
use App\Domain\Leave\Models\LeaveCarryForward;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\People\Models\Employee;
use App\Jobs\Leave\RunAccrual;
use App\Support\Spreadsheet\TabularWriter;
use Illuminate\Http\Request;

class AccrualController extends LedgerController
{
    public function __construct(private AccrualRunService $runs) {}

    private function params(Request $r): array
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'leave_types' => 'nullable|array|max:50', 'leave_types.*' => 'uuid',
            'employees' => 'nullable|array|max:2000', 'employees.*' => 'uuid', 'queue' => 'nullable|boolean']);

        return ['from' => $d['from'], 'to' => $d['to'], 'mode' => 'manual',
            'leave_type_ids' => isset($d['leave_types']) ? LeaveType::query()->whereIn('uuid', $d['leave_types'])->pluck('id')->all() : [],
            'employee_ids' => $this->scopedEmployeeIds($r->user(), 'leave.accrual.run', $d['employees'] ?? [])];
    }

    private function present(LeaveAccrualRun $run): array
    {
        return ['id' => $run->uuid, 'run_key' => $run->run_key, 'mode' => $run->mode, 'status' => $run->status, 'window' => ['from' => substr((string) $run->getRawOriginal('period_start'), 0, 10), 'to' => substr((string) $run->getRawOriginal('period_end'), 0, 10)],
            'counts' => ['total' => $run->total_items, 'posted' => $run->posted_items, 'skipped' => $run->skipped_items, 'failed' => $run->failed_items], 'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(), 'created_at' => $run->created_at?->toIso8601String()];
    }

    private function presentItems($items): array
    {
        $emps = Employee::query()->with('person')->whereIn('id', $items->pluck('employee_id'))->get()->keyBy('id');
        $types = LeaveType::query()->whereIn('id', $items->pluck('leave_type_id'))->get()->keyBy('id');

        return $items->map(fn ($i) => ['employee' => ['id' => $emps[$i->employee_id]->uuid ?? null, 'employee_number' => $emps[$i->employee_id]->employee_number ?? null, 'name' => $emps[$i->employee_id]->person?->display_name ?? null],
            'leave_type' => ['id' => $types[$i->leave_type_id]->uuid ?? null, 'code' => $types[$i->leave_type_id]->code ?? null], 'status' => $i->status, 'units' => \App\Domain\Leave\Support\Units::normalize($i->getRawOriginal('units')),
            'reason' => $i->skip_reason, 'calculation' => $i->calculation])->values()->all();
    }

    public function index(Request $r)
    {
        $p = LeaveAccrualRun::query()->orderByDesc('id')->paginate($this->perPage($r, 20));

        return $this->page($p, collect($p->items())->map(fn ($x) => $this->present($x))->all());
    }

    public function show(string $run)
    {
        return ['data' => $this->present(LeaveAccrualRun::byUuid($run))];
    }

    public function items(Request $r, string $run)
    {
        $model = LeaveAccrualRun::byUuid($run);
        $p = LeaveAccrualItem::query()->where('run_id', $model->id)->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderBy('id')->paginate($this->perPage($r, 50));

        return $this->page($p, $this->presentItems(collect($p->items())));
    }

    public function preview(Request $r)
    {
        $run = $this->runs->preview($this->params($r), $r->user());

        return response()->json(['data' => $this->present($run)], 200);
    }

    public function store(Request $r)
    {
        $params = $this->params($r);
        if ($r->boolean('queue')) {
            $run = $this->runs->findOrCreate($params, $r->user());
            if ($run->status !== 'completed') {
                $run->forceFill(['status' => 'queued', 'requested_by' => $run->requested_by ?? $r->user()->id])->save();
                RunAccrual::dispatch($r->user()->tenant_id, $run->id, $r->user()->id);
            }

            return response()->json(['data' => $this->present($run->refresh())], 202);
        }
        $existing = LeaveAccrualRun::query()->where('run_key', $this->runs->normalize($params)['run_key'])->first();
        $duplicate = $existing?->status === 'completed';
        $run = $this->runs->run($params, $r->user());

        return response()->json(['data' => $this->present($run), 'duplicate' => $duplicate], $duplicate ? 200 : 201);
    }

    public function execute(Request $r, string $run)
    {
        return ['data' => $this->present($this->runs->execute(LeaveAccrualRun::byUuid($run), $r->user()))];
    }

    public function retry(Request $r, string $run)
    {
        return ['data' => $this->present($this->runs->retry(LeaveAccrualRun::byUuid($run), $r->user()))];
    }

    public function errors(Request $r, string $run)
    {
        $model = LeaveAccrualRun::byUuid($run);
        $items = LeaveAccrualItem::query()->where('run_id', $model->id)->whereIn('status', ['failed', 'skipped'])->orderBy('id')->get();
        $rows = $this->presentItems($items);
        if ($r->query('format') === 'csv') {
            $path = tempnam(sys_get_temp_dir(), 'lvacc');
            $w = TabularWriter::open('csv', $path, ['employee_number', 'employee', 'leave_type', 'status', 'reason']);
            foreach ($rows as $x) {
                $w->write([$x['employee']['employee_number'], $x['employee']['name'], $x['leave_type']['code'], $x['status'], $x['reason']]);
            }
            $w->close();

            return response()->download($path, 'leave-accrual-errors-'.substr($model->uuid, 0, 8).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend(true);
        }

        return ['data' => $rows];
    }

    // ---- carry forward ------------------------------------------------------------------------------------

    private function carryParams(Request $r): array
    {
        $d = $r->validate(['as_of' => 'nullable|date_format:Y-m-d', 'leave_types' => 'nullable|array', 'leave_types.*' => 'uuid', 'employees' => 'nullable|array|max:2000', 'employees.*' => 'uuid']);

        return ['as_of' => $d['as_of'] ?? \App\Domain\Tenancy\TenantClock::today(), 'leave_type_ids' => isset($d['leave_types']) ? LeaveType::query()->whereIn('uuid', $d['leave_types'])->pluck('id')->all() : [],
            'employee_ids' => $this->scopedEmployeeIds($r->user(), 'leave.accrual.run', $d['employees'] ?? [])];
    }

    private function presentCarry(array $res): array
    {
        $emps = Employee::query()->with('person')->whereIn('id', collect($res['items'])->pluck('employee_id'))->get()->keyBy('id');
        $types = LeaveType::query()->get()->keyBy('id');

        return ['run_key' => $res['run_key'], 'status' => $res['status'], 'items' => collect($res['items'])->map(fn ($c) => ['id' => $c->uuid, 'status' => $c->status, 'mode' => $c->mode,
            'employee' => ['id' => $emps[$c->employee_id]->uuid ?? null, 'employee_number' => $emps[$c->employee_id]->employee_number ?? null, 'name' => $emps[$c->employee_id]->person?->display_name ?? null],
            'leave_type' => ['id' => $types[$c->leave_type_id]->uuid ?? null, 'code' => $types[$c->leave_type_id]->code ?? null], 'eligible_units' => \App\Domain\Leave\Support\Units::normalize($c->getRawOriginal('eligible_units')),
            'carried_units' => \App\Domain\Leave\Support\Units::normalize($c->getRawOriginal('carried_units')), 'forfeited_units' => \App\Domain\Leave\Support\Units::normalize($c->getRawOriginal('forfeited_units')),
            'valid_until' => $c->valid_until ? substr((string) $c->getRawOriginal('valid_until'), 0, 10) : null, 'calculation' => $c->calculation])->values()->all()];
    }

    public function carryPreview(Request $r, CarryForwardService $carry)
    {
        return ['data' => $this->presentCarry($carry->preview($this->carryParams($r)))];
    }

    public function carryRun(Request $r, CarryForwardService $carry)
    {
        return ['data' => $this->presentCarry($carry->run($this->carryParams($r), $r->user()))];
    }

    public function carryIndex(Request $r)
    {
        $p = LeaveCarryForward::query()->when($r->query('run_key'), fn ($q, $k) => $q->where('run_key', 'like', $k.'%'))->orderByDesc('id')->paginate($this->perPage($r, 50));

        return $this->page($p, $this->presentCarry(['run_key' => (string) $r->query('run_key'), 'status' => 'list', 'items' => $p->items()])['items']);
    }

    public function expiryProcess(Request $r, ExpiryService $expiry)
    {
        $d = $r->validate(['as_of' => 'nullable|date_format:Y-m-d', 'dry_run' => 'nullable|boolean']);
        $res = $expiry->process($d['as_of'] ?? null, null, (bool) ($d['dry_run'] ?? false));
        unset($res['items']);

        return ['data' => $res + ['dry_run' => (bool) ($d['dry_run'] ?? false)] + (($d['dry_run'] ?? false) ? [] : ['alerts' => $expiry->alerts($d['as_of'] ?? null)])];
    }
}
