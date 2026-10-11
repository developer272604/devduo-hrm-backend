<?php

namespace App\Http\Controllers\Payroll\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Calculation\Formula;
use App\Domain\Payroll\Explainer\DifferenceView;
use App\Domain\Payroll\Explainer\PayrollExplainer;
use App\Domain\Payroll\Models\PayrollExport;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollSimulationRun;
use App\Domain\Payroll\Operations\CommandCenter;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Readiness\ReadinessEngine;
use App\Domain\Payroll\Reports\ReportCatalogue;
use App\Domain\Payroll\Security\SensitiveAccess;
use App\Domain\Payroll\Simulation\SimulationService;
use App\Domain\People\Boot\OperationsBoot as PeopleOps;
use App\Http\Controllers\Controller;
use App\Jobs\Payroll\GeneratePayrollExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Workstream D HTTP surface (thin): readiness, command center, explainer, difference, simulations, reports/exports, sensitive-access log. */
class OperationsController extends Controller
{
    // ---- readiness -------------------------------------------------------------------------------------------------
    public function readiness(Request $r, ReadinessEngine $engine)
    {
        $d = $r->validate(['group' => 'nullable|uuid', 'period' => 'nullable|uuid']);
        if (empty($d['group'])) {
            return $engine->overview($r->user());
        }
        $group = PayrollGroup::where('uuid', $d['group'])->firstOrFail();

        return ReadinessEngine::clean($engine->evaluate($r->user(), $group, $d['period'] ?? null));
    }

    public function readinessMap(Request $r, ReadinessEngine $engine)
    {
        $d = $r->validate(['by' => ['nullable', Rule::in(['payroll_group', 'legal_entity', 'location', 'department'])]]);

        return ['by' => $d['by'] ?? 'payroll_group', 'rule' => 'Percentages are ready employees / expected employees from explicit blocking conditions.', 'data' => $engine->map($r->user(), $d['by'] ?? 'payroll_group')];
    }

    public function commandCenter(Request $r, CommandCenter $center)
    {
        return $center->snapshot($r->user());
    }

    // ---- explainer / difference ------------------------------------------------------------------------------------
    public function explain(Request $r, PayrollExplainer $explainer, string $employee)
    {
        $r->validate(['run' => 'nullable|uuid']);
        SensitiveAccess::demandPurpose($r);
        $out = $explainer->explain($r->user(), $employee, $r->input('run'));
        SensitiveAccess::log($r->user(), PayrollOps::employees($r->user(), ['payroll.explain'])->where('e.uuid', $employee)->value('e.id'), 'payroll_result', 'reveal', true, 'explainer', $out['run']['uuid'], $r);

        return $out;
    }

    public function employeeDifference(Request $r, DifferenceView $view, PayrollExplainer $explainer, string $employee)
    {
        $d = $r->validate(['period' => 'nullable|uuid', 'against_period' => 'nullable|uuid']);
        SensitiveAccess::demandPurpose($r);
        $out = $view->employee($r->user(), $employee, $d['period'] ?? null, $d['against_period'] ?? null, $explainer);
        SensitiveAccess::log($r->user(), PayrollOps::employees($r->user(), ['payroll.explain'])->where('e.uuid', $employee)->value('e.id'), 'payroll_result', 'reveal', true, 'difference', null, $r);

        return $out;
    }

    public function runDifference(Request $r, DifferenceView $view, string $run)
    {
        $d = $r->validate(['against' => 'nullable|uuid', 'mode' => ['nullable', Rule::in(['run', 'recalculation'])], 'limit' => 'nullable|integer|min:1|max:100']);
        SensitiveAccess::demandPurpose($r);
        $out = $view->runs($r->user(), $run, $d['against'] ?? null, $d['mode'] ?? 'run', (int) ($d['limit'] ?? 25));
        SensitiveAccess::log($r->user(), null, 'payroll_result', 'reveal', true, 'run_difference', $run, $r);

        return $out;
    }

    // ---- simulations -----------------------------------------------------------------------------------------------
    /** Pre-existing formula sandbox (same path and shape as before). */
    public function simulate(Request $r)
    {
        $data = $r->validate(['expression' => 'required|string|max:1000', 'variables' => 'present|array|max:30', 'variables.*' => ['required', 'string', 'regex:/^-?\d{1,18}(?:\.\d{1,6})?$/D'], 'scale' => 'sometimes|integer|min:0|max:6']);
        try {
            $result = (new Formula)->calculate($data['expression'], $data['variables'], $data['scale'] ?? 2);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['expression' => $e->getMessage()]);
        }

        return ['result' => $result, 'expression' => $data['expression'], 'variables' => $data['variables'], 'rounding' => 'half_up', 'persisted' => false];
    }

    public function simulationStore(Request $r, SimulationService $service)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(SimulationService::KINDS)], 'employees' => 'required|array|min:1|max:'.SimulationService::MAX_EMPLOYEES, 'employees.*' => 'uuid', 'title' => 'nullable|string|max:160',
            'params' => 'required|array', 'params.percent' => ['required_if:kind,salary_revision', 'regex:/^-?\d{1,3}(?:\.\d{1,4})?$/D'], 'params.amount' => ['required_if:kind,bonus', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/D'], 'params.days' => ['required_if:kind,lop', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D']]);
        $params = collect($d['params'])->only(['salary_revision' => 'percent', 'bonus' => 'amount', 'lop' => 'days'][$d['kind']])->all();

        return response()->json($service->run($r->user(), $d['kind'], $params, $d['employees'], $d['title'] ?? null), 201);
    }

    public function simulations(Request $r)
    {
        return PayrollSimulationRun::where('created_by', $r->user()->id)->latest('id')->limit(50)->get()->map(fn ($s) => ['uuid' => $s->uuid, 'kind' => $s->kind, 'title' => $s->title, 'status' => $s->status, 'created_at' => PayrollOps::iso($s->created_at)]);
    }

    public function simulation(Request $r, SimulationService $service, string $uuid)
    {
        $s = PayrollSimulationRun::where('created_by', $r->user()->id)->where('uuid', $uuid)->firstOrFail();

        return $service->present($s->result ?? [], $s->uuid, $r->user()->allows('payroll.compensation.reveal'));
    }

    // ---- reports / exports -----------------------------------------------------------------------------------------
    public function reports(ReportCatalogue $c)
    {
        return ['reports' => $c->catalogue()];
    }

    public function report(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');
        $r->validate(['run' => 'required|uuid', 'against' => 'nullable|uuid', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);

        return $c->page($r->user(), $report, $r->only(['run', 'against']), (int) $r->input('page', 1), (int) $r->input('per_page', 25));
    }

    public function export(Request $r, ReportCatalogue $c, string $report)
    {
        abort_unless($c->exists($report), 404, 'That report does not exist.');
        $d = $r->validate(['format' => 'required|in:csv,xlsx', 'run' => 'required|uuid', 'against' => 'nullable|uuid']);
        if (\App\Domain\Payroll\Support\PayrollSettings::get('exports.require_purpose', true)) {
            SensitiveAccess::demandPurpose($r);
        }
        [$code, $note] = SensitiveAccess::purpose($r);
        $export = PayrollExport::create(['report' => $report, 'format' => $d['format'], 'status' => 'queued', 'filters' => array_filter(['run' => $d['run'], 'against' => $d['against'] ?? null]), 'created_by' => $r->user()->id,
            'purpose_code' => $code, 'purpose_note' => $note, 'masked' => ! $r->user()->allows('payroll.results.view')]);
        SensitiveAccess::log($r->user(), null, 'export', 'export', ! $export->masked, 'report:'.$report, $export->uuid, $r);
        GeneratePayrollExport::dispatch(PayrollOps::tenantId(), $export->id);

        return response()->json($this->exportRow($export->fresh()), 202);
    }

    public function exports(Request $r)
    {
        return ['data' => PayrollExport::where('created_by', $r->user()->id)->latest('id')->limit(50)->get()->map(fn ($x) => $this->exportRow($x))->all()];
    }

    public function download(Request $r, string $uuid)
    {
        $x = PayrollExport::where('created_by', $r->user()->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless($x->status === 'ready' && $x->file_path && $x->expires_at && $x->expires_at->isFuture(), 410, 'This export is not available any more. Please create a new one.');
        SensitiveAccess::log($r->user(), null, 'export', 'download', ! $x->masked, 'report:'.$x->report, $x->uuid, $r);

        return Storage::disk(PeopleOps::EXPORTS)->download($x->file_path, 'payroll-'.$x->report.'-'.substr($x->uuid, 0, 8).'.'.$x->format);
    }

    // ---- sensitive-access log --------------------------------------------------------------------------------------
    public function sensitiveAccess(Request $r)
    {
        Audit::record('payroll.sensitive_log_viewed', 'payroll', 'sensitive-access');
        $rows = DB::table('payroll_sensitive_access_logs as l')->join('users as u', 'u.id', '=', 'l.user_id')->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')->where('l.tenant_id', PayrollOps::tenantId())
            ->orderByDesc('l.id')->paginate(min(100, max(1, (int) $r->input('per_page', 25))), ['l.id', 'u.name as actor', 'e.uuid as employee_uuid', 'l.data_type', 'l.action', 'l.purpose_code', 'l.purpose_note', 'l.revealed', 'l.surface', 'l.record_ref', 'l.created_at']);

        return $rows;
    }

    public function purposes()
    {
        return ['required' => SensitiveAccess::purposeRequired(), 'purposes' => SensitiveAccess::PURPOSES, 'note' => 'Send purpose_code (and purpose_note, min 10 characters for "other") with any request that reveals figures.'];
    }

    private function exportRow(PayrollExport $x): array
    {
        return ['uuid' => $x->uuid, 'report' => $x->report, 'format' => $x->format, 'status' => $x->status, 'row_count' => (int) $x->row_count, 'masked' => (bool) $x->masked, 'expires_at' => PayrollOps::iso($x->expires_at),
            'error' => $x->error, 'created_at' => PayrollOps::iso($x->created_at), 'download' => $x->status === 'ready' ? '/api/v1/payroll/exports/'.$x->uuid.'/download' : null];
    }
}
