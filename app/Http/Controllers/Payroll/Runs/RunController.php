<?php

namespace App\Http\Controllers\Payroll\Runs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Engine\RunCalculator;
use App\Domain\Payroll\Inputs\InputCollector;
use App\Domain\Payroll\Locks\PayrollLockService;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollValidationIssue;
use App\Domain\Payroll\Runs\ControlTotals;
use App\Domain\Payroll\Runs\RunService;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Validation\ValidationEngine;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payroll runs (workstream B). Thin: rules live in Domain/Payroll/{Runs,Engine,Locks,Validation}. */
final class RunController
{
    public function __construct(private RunService $runs, private PayrollLockService $locks) {}

    public function index(Request $r)
    {
        $q = PayrollRun::with(['period', 'group'])->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('period_uuid'), fn ($q, $u) => $q->whereIn('period_id', \App\Domain\Payroll\Models\PayrollPeriod::where('uuid', $u)->select('id')))
            ->orderBy(in_array($r->query('sort'), ['created_at', 'status', 'run_number'], true) ? $r->query('sort') : 'id', $r->query('dir') === 'asc' ? 'asc' : 'desc');

        return $q->paginate(min((int) $r->query('per_page', 25), 100))->through(fn ($run) => $this->present($run, false));
    }

    public function preview(Request $r)
    {
        return $this->runs->preview($r->user(), $this->createData($r));
    }

    public function store(Request $r)
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[0-9a-f-]{36}$/i', $key), 422, 'An Idempotency-Key header (UUID) is required.');
        $run = $this->runs->create($r->user(), $this->createData($r), $key);

        return response()->json($this->present($run), $run->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $r, string $uuid)
    {
        return $this->present($this->find($uuid));
    }

    public function employees(Request $r, string $uuid)
    {
        $run = $this->find($uuid);
        $canSee = $r->user()->allows('payroll.results.view');
        if ($canSee) {
            Audit::record('payroll.results.viewed', 'payroll', $run->uuid);
        }
        $q = app(DataScope::class)->constrain(PayrollRunEmployee::query()->where('run_id', $run->id)->join('employees', 'employees.id', '=', 'payroll_run_employees.employee_id')->select('payroll_run_employees.*'), $r->user(), 'payroll.view')
            ->with(['employee.person', 'currentResult'])->orderBy('payroll_run_employees.id');

        return $q->paginate(min((int) $r->query('per_page', 50), 100))->through(fn ($re) => ['uuid' => $re->uuid, 'employee_uuid' => $re->employee->uuid, 'employee_name' => $re->employee->person->display_name, 'employee_number' => $re->employee->employee_number,
            'status' => $re->status, 'exclusion_reason' => $re->exclusion_reason, 'snapshot' => $re->employment_snapshot, 'errors' => $canSee ? json_decode($re->last_error ?? 'null', true) : null,
            'result' => $canSee && $re->currentResult ? $this->presentResult($re->currentResult, false) : null]);
    }

    public function setEmployee(Request $r, string $uuid, string $employeeUuid)
    {
        $d = $r->validate(['action' => ['required', Rule::in(['excluded', 'held', 'include'])], 'reason' => 'nullable|string|max:160']);
        $re = $this->runs->setEmployeeStatus($r->user(), $this->find($uuid), $employeeUuid, $d['action'], $d['reason'] ?? null);

        return ['uuid' => $re->uuid, 'status' => $re->status, 'exclusion_reason' => $re->exclusion_reason];
    }

    public function collectInputs(Request $r, string $uuid, InputCollector $collector)
    {
        $run = $this->find($uuid);
        abort_unless(in_array($run->status, ['inputs', 'review', 'reopened', 'validating'], true), 423, 'Inputs cannot be collected in the current run state.');
        PayrollLockService::logChange($run, 'inputs_collected');

        return $collector->collect($run, $r->user());
    }

    public function calculate(Request $r, string $uuid, RunCalculator $calculator)
    {
        $run = $calculator->start($this->find($uuid), $r->user()->id);

        return response()->json($this->present($run), 202);
    }

    public function progress(string $uuid)
    {
        $run = $this->find($uuid);
        $count = fn (array $s) => PayrollRunEmployee::where('run_id', $run->id)->whereIn('status', $s)->count();
        $p = $run->progress ?? [];
        $done = PayrollRunEmployee::where('run_id', $run->id)->whereNotIn('status', ['excluded', 'held'])->where('calc_pass', '>=', max(1, $run->calculation_version))->count();

        return ['status' => $run->status, 'pass' => $run->calculation_version, 'total' => $p['total'] ?? $run->employees_total, 'done' => $done, 'failed' => $count(['failed']), 'finished_at' => $p['finished_at'] ?? null];
    }

    public function validateRun(string $uuid, ValidationEngine $engine, ControlTotals $totals)
    {
        $run = $this->find($uuid);
        abort_unless(in_array($run->status, ['review', 'reopened', 'inputs'], true), 423, 'Validation can only be refreshed before approval.');
        $run->control_totals = $totals->compute($run);
        $run->save();

        return $engine->validate($run);
    }

    public function validations(Request $r, string $uuid)
    {
        $run = $this->find($uuid);
        $q = PayrollValidationIssue::where('run_id', $run->id)->when($r->query('severity'), fn ($q, $s) => $q->where('severity', $s))->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case severity when 'critical' then 0 when 'blocking' then 1 when 'warning' then 2 else 3 end")->orderBy('id');
        $employees = Employee::with('person')->whereIn('id', (clone $q)->pluck('employee_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return ['summary' => app(ValidationEngine::class)->summary($run), 'data' => $q->limit(500)->get()->map(fn ($i) => ['uuid' => $i->uuid, 'code' => $i->code, 'severity' => $i->severity, 'message' => $i->message, 'status' => $i->status,
            'employee_uuid' => $employees[$i->employee_id]->uuid ?? null, 'employee_name' => $employees[$i->employee_id]->person->display_name ?? null, 'details' => $i->details, 'action' => $i->action, 'resolution_note' => $i->resolution_note])];
    }

    public function waive(Request $r, string $issueUuid)
    {
        $d = $r->validate(['note' => 'required|string|min:5|max:400']);
        $i = $this->runs->waive($r->user(), $issueUuid, $d['note']);

        return ['uuid' => $i->uuid, 'status' => $i->status];
    }

    public function totals(string $uuid, ControlTotals $totals)
    {
        $run = $this->find($uuid);
        Audit::record('payroll.totals.viewed', 'payroll', $run->uuid);

        return ['stored' => $run->control_totals, 'live' => $totals->compute($run)];
    }

    public function result(Request $r, string $uuid, string $employeeUuid)
    {
        $run = $this->find($uuid);
        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $employee, 'payroll.results.view'), 404);
        $re = PayrollRunEmployee::where('run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $results = PayrollCalculationResult::where('run_employee_id', $re->id)->orderByDesc('version')->get();
        Audit::record('payroll.result.viewed', 'payroll', $run->uuid, ['employee_uuid' => $employeeUuid]);

        return ['employee_uuid' => $employee->uuid, 'status' => $re->status, 'current' => ($c = $results->firstWhere('is_current', true)) ? $this->presentResult($c, true) : null,
            'versions' => $results->map(fn ($v) => ['uuid' => $v->uuid, 'version' => $v->version, 'is_current' => (bool) $v->is_current, 'net' => Decimal::str($v->net, 4), 'checksum' => $v->checksum, 'created_at' => $v->created_at])];
    }

    public function verify(Request $r, string $uuid, string $employeeUuid, RunCalculator $calculator)
    {
        $run = $this->find($uuid);
        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $employee, 'payroll.results.view'), 404);
        $re = PayrollRunEmployee::where('run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

        return $calculator->proof($re->id);
    }

    public function submit(Request $r, string $uuid)
    {
        return $this->present($this->locks->submit($this->find($uuid), $r->user()));
    }

    public function approve(Request $r, string $uuid)
    {
        return $this->present($this->locks->decide($this->find($uuid), $r->user(), 'approved', $r->input('comment')));
    }

    public function reject(Request $r, string $uuid)
    {
        $d = $r->validate(['comment' => 'required|string|min:3|max:400']);

        return $this->present($this->locks->decide($this->find($uuid), $r->user(), 'rejected', $d['comment']));
    }

    public function lock(Request $r, string $uuid)
    {
        return $this->present($this->locks->lock($this->find($uuid), $r->user(), $r->input('note')));
    }

    public function reopenRequest(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return $this->present($this->locks->requestReopen($this->find($uuid), $r->user(), $d['reason']));
    }

    public function reopenDecision(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'comment' => 'nullable|string|max:400']);

        return $this->present($this->locks->decideReopen($this->find($uuid), $r->user(), $d['decision'], $d['comment'] ?? null));
    }

    public function cancel(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:300']);

        return $this->present($this->runs->cancel($r->user(), $this->find($uuid), $d['reason']));
    }

    private function createData(Request $r): array
    {
        return $r->validate(['period_uuid' => 'required_unless:run_type,correction|nullable|uuid', 'run_type' => ['nullable', Rule::in(RunService::RUN_TYPES)], 'off_cycle_reason' => 'nullable|string|max:40',
            'parent_run_uuid' => 'nullable|uuid', 'employee_uuids' => 'nullable|array|max:5000', 'employee_uuids.*' => 'uuid', 'notes' => 'nullable|string|max:1000']);
    }

    private function find(string $uuid): PayrollRun
    {
        return PayrollRun::with(['period', 'group'])->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function present(PayrollRun $run, bool $detail = true): array
    {
        $run->loadMissing(['period', 'group']);
        $out = ['uuid' => $run->uuid, 'run_number' => $run->run_number, 'run_type' => $run->run_type, 'off_cycle_reason' => $run->off_cycle_reason, 'status' => $run->status, 'stage' => $run->stage,
            'stage_name' => \App\Domain\Payroll\Support\PayrollStates::RUN_STAGES[$run->stage] ?? null, 'group' => ['uuid' => $run->group->uuid, 'name' => $run->group->name, 'code' => $run->group->code],
            'period' => ['uuid' => $run->period->uuid, 'label' => $run->period->label, 'start' => substr((string) $run->period->period_start, 0, 10), 'end' => substr((string) $run->period->period_end, 0, 10), 'state' => $run->period->state],
            'currency' => $run->currency, 'employees_total' => $run->employees_total, 'employees_failed' => $run->employees_failed, 'calculation_version' => $run->calculation_version, 'calculated_at' => $run->calculated_at,
            'approved_at' => $run->approved_at, 'locked_at' => $run->locked_at, 'created_at' => $run->created_at, 'parent_run_uuid' => $run->parent_run_id ? PayrollRun::whereKey($run->parent_run_id)->value('uuid') : null];
        if ($detail) {
            $out += ['progress' => $run->progress, 'notes' => $run->notes, 'engine_version' => $run->engine_version, 'country_pack_version_id' => $run->country_pack_version_id,
                'readiness' => $run->settings_snapshot['readiness'] ?? null, 'validation' => app(ValidationEngine::class)->summary($run),
                'control_totals' => auth()->user()?->allows('payroll.results.view') ? $run->control_totals : null,
                'can' => ['calculate' => in_array($run->status, \App\Domain\Payroll\Support\PayrollStates::CALCULABLE_RUN, true), 'submit' => $run->status === 'review',
                    'approve' => $run->status === 'approval' && auth()->user()?->allows('payroll.approve') && auth()->id() !== $run->created_by, 'lock' => $run->status === 'approved' && (bool) auth()->user()?->allows('payroll.lock')]];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function presentResult(PayrollCalculationResult $res, bool $components): array
    {
        $out = ['uuid' => $res->uuid, 'version' => $res->version, 'status' => $res->status, 'currency' => $res->currency, 'gross' => Decimal::str($res->gross, 4), 'total_deductions' => Decimal::str($res->total_deductions, 4),
            'total_taxes' => Decimal::str($res->total_taxes, 4), 'reimbursements' => Decimal::str($res->reimbursements, 4), 'employer_contributions' => Decimal::str($res->employer_contributions, 4), 'benefits' => Decimal::str($res->benefits, 4),
            'net' => Decimal::str($res->net, 4), 'payable_days' => $res->payable_days === null ? null : Decimal::str($res->payable_days, 4), 'working_days' => $res->working_days === null ? null : Decimal::str($res->working_days, 4),
            'engine_version' => $res->engine_version, 'country_pack_version_id' => $res->country_pack_version_id, 'warnings' => $res->warnings, 'errors' => $res->errors, 'checksum' => $res->checksum];
        if ($components) {
            $out['components'] = $res->components()->orderBy('sequence')->get()->map(fn ($c) => ['code' => $c->component_code, 'name' => $c->name, 'kind' => $c->kind, 'amount' => Decimal::str($c->amount, 4), 'taxable_amount' => $c->taxable_amount === null ? null : Decimal::str($c->taxable_amount, 4),
                'quantity' => $c->quantity === null ? null : Decimal::str($c->quantity, 6), 'formula' => $c->formula, 'formula_version' => $c->formula_version, 'component_version' => $c->component_version, 'origin' => $c->origin, 'source_ref' => $c->source_ref, 'trace' => $c->trace])->all();
            $out['trace'] = $res->trace;
        }

        return $out;
    }
}
