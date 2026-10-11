<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Leave\Explainer\LeaveExplainer;
use App\Domain\Leave\Models\LeaveSimulationRun;
use App\Domain\Leave\Operations\CommandCenter;
use App\Domain\Leave\Operations\Confidence;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Operations\TimeStory;
use App\Domain\Leave\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explainer, simulations, forecast, confidence, command center and time story. All reads; simulations only record a simulation run. */
class InsightController extends Controller
{
    private const OTHERS = ['leave.team.view', 'leave.manage', 'leave.reports.view'];

    // ---- explainer ---------------------------------------------------------------------------------------
    public function explainBalance(Request $r, LeaveExplainer $x)
    {
        $r->validate(['employee' => 'nullable|string', 'leave_type' => 'nullable|string', 'as_of' => 'nullable|date_format:Y-m-d', 'units' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/']]);
        $employee = $this->employee($r);
        $typeId = $r->filled('leave_type') ? (int) DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('code', $r->input('leave_type'))->value('id') : null;
        abort_if($r->filled('leave_type') && ! $typeId, 404, 'Unknown leave type.');
        $out = $x->balance($employee, $typeId, $r->input('as_of'), $r->input('units'));
        if (! $this->isSelf($r, $employee) && ! LeaveOps::medical($r->user())) {
            $sensitive = DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('is_sensitive', true)->pluck('code')->all();
            $out['accounts'] = array_values(array_filter($out['accounts'], fn ($a) => ! in_array($a['leave_type']['code'], $sensitive, true)));
        }

        return $out;
    }

    public function explainRequest(Request $r, LeaveExplainer $x, string $uuid)
    {
        $row = DB::table('leave_requests')->where('tenant_id', LeaveOps::tenantId())->where('uuid', $uuid)->first(['employee_id', 'is_sensitive']);
        abort_unless($row, 404);
        $own = LeaveOps::ownEmployee($r->user());
        $self = $own && (int) $own->id === (int) $row->employee_id && $r->user()->allows('leave.view');
        abort_unless($self || LeaveOps::employees($r->user(), self::OTHERS)->where('e.id', $row->employee_id)->exists(), 404);
        abort_if($row->is_sensitive && ! $self && ! LeaveOps::medical($r->user()), 404);

        return $x->request($uuid) ?? abort(404);
    }

    // ---- simulations (leave.simulate) ------------------------------------------------------------------
    public function sandbox(Request $r, SimulationService $s)
    {
        $d = $r->validate(['leave_type_id' => 'required|integer', 'rules' => 'nullable|array', 'policy_version_id' => 'nullable|integer', 'employees' => 'required|array|min:1|max:'.SimulationService::MAX_EMPLOYEES, 'employees.*' => 'string',
            'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start']);
        $ids = LeaveOps::employees($r->user(), 'leave.simulate')->whereIn('e.uuid', $d['employees'])->pluck('e.id')->map(fn ($v) => (int) $v)->all();
        abort_if($ids === [], 404, 'None of the selected employees are in your scope.');
        unset($d['employees']);

        return $s->sandbox($r->user(), $d, $ids);
    }

    public function entitlement(Request $r, SimulationService $s)
    {
        $d = $r->validate(['employee' => 'required|string', 'leave_type_id' => 'required|integer', 'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'accrual_from' => 'nullable|date_format:Y-m-d', 'accrual_to' => 'nullable|date_format:Y-m-d']);
        $employee = LeaveOps::visibleEmployee($r->user(), $d['employee'], 'leave.simulate', null);
        unset($d['employee']);

        return $s->entitlement($r->user(), $employee, $d);
    }

    public function impact(Request $r, SimulationService $s)
    {
        $d = $r->validate(['employee' => 'nullable|string', 'leave_type_id' => 'required|integer', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d', 'portion' => 'nullable|in:full_day,first_half,second_half,quarter,hours',
            'start_time' => 'nullable|string', 'end_time' => 'nullable|string', 'units' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/']]);
        $own = LeaveOps::ownEmployee($r->user());
        $uuid = $d['employee'] ?? $own?->uuid;
        abort_unless($uuid, 404);
        $self = $own && $own->uuid === $uuid && ($r->user()->allows('leave.request') || $r->user()->allows('leave.simulate'));
        $employee = $self ? LeaveOps::employeeByUuid($uuid) : LeaveOps::visibleEmployee($r->user(), $uuid, 'leave.simulate', null);
        unset($d['employee']);
        $out = $s->requestImpact($r->user(), $employee, $d);
        if (! $r->user()->allows('leave.team.view') && ! $r->user()->allows('leave.manage')) {
            $out['team_overlap'] = ['count' => $out['team_overlap']['count'], 'people' => []]; // an employee sees only how many colleagues overlap
        }

        return $out;
    }

    public function diff(Request $r, SimulationService $s)
    {
        $d = $r->validate(['from' => 'required|integer', 'to' => 'required|integer']);

        return $s->diff($r->user(), (int) $d['from'], (int) $d['to']);
    }

    public function simulations(Request $r)
    {
        $rows = LeaveSimulationRun::query()->when($r->input('kind'), fn ($q, $k) => $q->where('kind', $k))->orderByDesc('id')->limit(50)->get();

        return ['data' => $rows->map(fn ($x) => ['uuid' => $x->uuid, 'kind' => $x->kind, 'created_at' => LeaveOps::iso($x->created_at), 'requested_by' => $x->requested_by])];
    }

    public function simulation(string $uuid)
    {
        $x = LeaveSimulationRun::byUuid($uuid);

        return ['data' => ['uuid' => $x->uuid, 'kind' => $x->kind, 'input' => $x->input, 'result' => $x->result, 'created_at' => LeaveOps::iso($x->created_at)]];
    }

    // ---- forecast / confidence / story / command center ------------------------------------------------
    public function forecast(Request $r, SimulationService $s)
    {
        $r->validate(['employee' => 'nullable|string', 'leave_type' => 'nullable|string', 'months' => 'nullable|integer|min:1|max:24']);
        $employee = $this->employee($r);
        $typeId = $r->filled('leave_type') ? (int) DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('code', $r->input('leave_type'))->value('id') : null;

        return $s->forecast($employee, $typeId ?: null, (int) $r->input('months', 6));
    }

    public function confidence(Request $r, Confidence $c)
    {
        $r->validate(['employee' => 'nullable|string']);
        $employee = $this->employee($r);

        return ['employee_uuid' => $employee->uuid] + $c->forEmployee((int) $employee->id);
    }

    public function timeStory(Request $r, TimeStory $t)
    {
        $r->validate(['employee' => 'nullable|string', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        return $t->build($r->user(), $this->employee($r), $r->input('from'), $r->input('to'));
    }

    public function commandCenter(Request $r, CommandCenter $c)
    {
        return $c->snapshot($r->user());
    }

    // ----------------------------------------------------------------------------------------------------------
    /** The requested employee (uuid) the viewer may see, or the viewer's own employment. */
    private function employee(Request $r): object
    {
        $own = LeaveOps::ownEmployee($r->user());
        $uuid = $r->input('employee') ?: $own?->uuid;
        abort_unless($uuid, 404, 'No employee selected.');

        return LeaveOps::visibleEmployee($r->user(), $uuid, self::OTHERS, 'leave.view');
    }

    private function isSelf(Request $r, object $employee): bool
    {
        $own = LeaveOps::ownEmployee($r->user());

        return $own && (int) $own->id === (int) $employee->id;
    }
}
