<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Models\LeaveTransfer;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Models\LeaveTypeMapping;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Transfers\TransferService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Transfer / separation / rehire handling and leave type mappings. Previews are stored; applying posts through the ledger only. */
class TransferController extends Controller
{
    public function index(Request $r)
    {
        $scope = LeaveOps::employees($r->user(), 'leave.manage')->select('e.id');
        $rows = LeaveTransfer::query()->whereIn('employee_id', $scope)->when($r->input('status'), fn ($q, $s) => $q->where('status', $s))->when($r->input('trigger'), fn ($q, $t) => $q->where('trigger', $t))->orderByDesc('id')->limit(100)->get();

        return ['data' => $rows->map(fn ($t) => $this->present($t))];
    }

    public function show(Request $r, string $uuid)
    {
        return ['data' => $this->present($this->find($r, $uuid), true)];
    }

    public function preview(Request $r, TransferService $svc)
    {
        $d = $r->validate(['employee' => 'required|string', 'trigger' => 'required|in:transfer,separation,rehire,policy_change', 'effective_date' => 'required|date_format:Y-m-d', 'balance_mode' => 'nullable|in:preserve,transfer,convert,expire', 'previous_employee' => 'nullable|string']);
        $employee = LeaveOps::visibleEmployee($r->user(), $d['employee'], 'leave.manage', null);
        $opts = ['mode' => $d['balance_mode'] ?? null];
        if ($d['trigger'] === 'rehire') {
            $previous = LeaveOps::visibleEmployee($r->user(), $d['previous_employee'] ?? '', 'leave.manage', null);
            $opts['previous_employee_id'] = (int) $previous->id;
        }
        $row = $svc->preview((int) $employee->id, $d['trigger'], $d['effective_date'], $opts, $r->user());

        return response()->json(['data' => $this->present($row, true)], 201);
    }

    public function apply(Request $r, TransferService $svc, string $uuid)
    {
        $row = $this->find($r, $uuid);
        $r->validate(['balance_mode' => 'nullable|in:preserve,transfer,convert,expire']);

        return ['data' => $this->present($svc->apply($row, $r->user(), $r->input('balance_mode')), true)];
    }

    public function cancel(Request $r, TransferService $svc, string $uuid)
    {
        return ['data' => $this->present($svc->cancel($this->find($r, $uuid)))];
    }

    // ---- leave type mappings ---------------------------------------------------------------------------
    public function mappings()
    {
        $codes = LeaveType::query()->pluck('code', 'id');

        return ['data' => LeaveTypeMapping::query()->orderBy('id')->get()->map(fn ($m) => ['id' => $m->id, 'from' => $codes[$m->from_leave_type_id] ?? null, 'to' => $codes[$m->to_leave_type_id] ?? null, 'ratio' => (string) $m->ratio, 'purpose' => $m->purpose])];
    }

    public function mappingStore(Request $r)
    {
        $d = $r->validate(['from' => 'required|string', 'to' => 'required|string|different:from', 'ratio' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,4})?$/'], 'purpose' => 'nullable|in:transfer,import,conversion']);
        $from = LeaveType::query()->where('code', $d['from'])->firstOrFail();
        $to = LeaveType::query()->where('code', $d['to'])->firstOrFail();
        $ratio = $d['ratio'] ?? '1';
        abort_if(bccomp($ratio, '0', 4) <= 0, 422, 'The ratio must be greater than zero.');
        $m = LeaveTypeMapping::query()->updateOrCreate(['from_leave_type_id' => $from->id, 'to_leave_type_id' => $to->id, 'purpose' => $d['purpose'] ?? 'transfer'], ['ratio' => $ratio]);
        Audit::record('mapping_saved', 'leave', 'leave_type_mapping:'.$m->id, ['from' => $from->code, 'to' => $to->code, 'ratio' => $ratio]);

        return response()->json(['data' => ['id' => $m->id, 'from' => $from->code, 'to' => $to->code, 'ratio' => (string) $m->ratio, 'purpose' => $m->purpose]], 201);
    }

    public function mappingDestroy(int $id)
    {
        $m = LeaveTypeMapping::query()->findOrFail($id);
        $m->delete();
        Audit::record('mapping_deleted', 'leave', 'leave_type_mapping:'.$id, []);

        return response()->noContent();
    }

    // ----------------------------------------------------------------------------------------------------------
    private function find(Request $r, string $uuid): LeaveTransfer
    {
        $row = LeaveTransfer::byUuid($uuid);
        abort_unless(LeaveOps::employees($r->user(), 'leave.manage')->where('e.id', $row->employee_id)->exists(), 404);

        return $row;
    }

    private function present(LeaveTransfer $t, bool $full = false): array
    {
        $e = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $t->employee_id)->first(['e.uuid', 'e.employee_number', 'p.display_name']);

        return ['uuid' => $t->uuid, 'employee' => ['uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name], 'trigger' => $t->trigger, 'effective_date' => substr((string) $t->getRawOriginal('effective_date'), 0, 10),
            'balance_mode' => $t->balance_mode, 'status' => $t->status, 'created_at' => LeaveOps::iso($t->created_at)] + ($full ? ['plan' => $t->plan, 'result' => $t->result] : ['warnings' => count($t->plan['warnings'] ?? [])]);
    }
}
