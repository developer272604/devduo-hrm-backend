<?php

namespace App\Http\Controllers\Leave\Ledger;

use App\Domain\Attendance\Operations\Ops;
use App\Domain\Leave\Adjustments\AdjustmentService;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;

class AdjustmentController extends LedgerController
{
    public function __construct(private AdjustmentService $adjustments) {}

    private function rules(bool $bulk): array
    {
        return ['leave_type' => 'required|uuid', 'units' => 'required|numeric', 'kind' => 'nullable|in:adjustment,grant,debit', 'effective_date' => 'nullable|date_format:Y-m-d', 'expires_on' => 'nullable|date_format:Y-m-d',
            'reason' => 'required|string|max:1000'] + ($bulk ? ['employees' => 'required|array|min:1|max:'.AdjustmentService::MAX_BULK, 'employees.*' => 'string|max:64', 'idempotency_key' => 'nullable|string|max:80'] : ['employee' => 'required|uuid']);
    }

    private function payload(array $d, Request $r): array
    {
        $out = ['leave_type_id' => $this->typeByUuid($d['leave_type'])->id, 'units' => (string) $d['units'], 'kind' => $d['kind'] ?? 'adjustment', 'effective_date' => $d['effective_date'] ?? null, 'expires_on' => $d['expires_on'] ?? null, 'reason' => $d['reason']];
        $out = array_filter($out, fn ($v) => $v !== null);
        if (isset($d['employee'])) {
            $e = $this->employeeByUuid($d['employee']);
            abort_unless(app(\App\Domain\Access\DataScope::class)->canAccess($r->user(), $e, 'leave.adjust'), 404);
            $out['employee_id'] = $e->id;
        }
        if (isset($d['employees'])) {
            $map = Employee::query()->whereIn('uuid', $d['employees'])->pluck('id', 'uuid');
            $out['employee_ids'] = collect($d['employees'])->map(fn ($u) => $map[$u] ?? $u)->all();
            $out['idempotency_key'] = $d['idempotency_key'] ?? null;
        }

        return $out;
    }

    public function index(Request $r)
    {
        $reach = Ops::employees($r->user(), ['leave.adjust', 'leave.adjust.approve'], false)->select('e.id');
        $p = LeaveAdjustment::query()->whereIn('employee_id', $reach)->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('bulk_key'), fn ($q, $k) => $q->where('bulk_key', $k))
            ->orderByDesc('id')->paginate($this->perPage($r, 25));

        return $this->page($p, collect($p->items())->map(fn ($a) => $this->adjustments->present($a))->all());
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules(false));
        $a = $this->adjustments->request($this->payload($d, $r), $r->user());

        return response()->json(['data' => $this->adjustments->present($a)], 201);
    }

    public function approve(Request $r, string $adjustment)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return ['data' => $this->adjustments->present($this->adjustments->approve(LeaveAdjustment::byUuid($adjustment), $r->user(), $d['note'] ?? null))];
    }

    public function reject(Request $r, string $adjustment)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return ['data' => $this->adjustments->present($this->adjustments->reject(LeaveAdjustment::byUuid($adjustment), $r->user(), $d['note'] ?? null))];
    }

    public function bulkPreview(Request $r)
    {
        $d = $r->validate($this->rules(true));

        return ['data' => $this->adjustments->previewBulk($this->payload($d, $r), $r->user())];
    }

    public function bulkStore(Request $r)
    {
        $d = $r->validate($this->rules(true));
        $res = $this->adjustments->createBulk($this->payload($d, $r), $r->user());

        return response()->json(['data' => ['bulk_key' => $res['bulk_key'], 'created' => $res['created'], 'skipped' => $res['skipped'], 'duplicate' => $res['duplicate'],
            'adjustments' => collect($res['adjustments'])->map(fn ($a) => $this->adjustments->present($a))->all()]], $res['duplicate'] ? 200 : 201);
    }

    public function bulkApprove(Request $r, string $bulkKey)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);
        abort_unless($r->user()->allows('leave.adjust.approve'), 403);

        return ['data' => $this->adjustments->approveBulk($bulkKey, $r->user(), $d['note'] ?? null)];
    }

    public function bulkReject(Request $r, string $bulkKey)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return ['data' => ['rejected' => $this->adjustments->rejectBulk($bulkKey, $r->user(), $d['note'] ?? null)]];
    }
}
