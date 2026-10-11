<?php

namespace App\Http\Controllers\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Compensation\CompensationImportService;
use App\Domain\Payroll\Compensation\CompensationResolverImpl;
use App\Domain\Payroll\Compensation\CompensationService;
use App\Domain\Payroll\Compensation\RevisionBatchService;
use App\Domain\Payroll\Compensation\RevisionService;
use App\Domain\Payroll\Models\CompensationRevision;
use App\Domain\Payroll\Models\CompensationRevisionBatch;
use App\Domain\Payroll\Models\EmployeeCompensation;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Structures\StructureService;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Compensation + structures + revisions. Amounts are returned only to viewers holding payroll.compensation.reveal (others get masked: true); every sensitive read is audited. */
final class CompensationController
{
    private function reveal(Request $r): bool
    {
        return $r->user()->allows('payroll.compensation.reveal');
    }

    private function employee(Request $r, string $uuid, string $permission): Employee
    {
        return app(DataScope::class)->constrain(Employee::query(), $r->user(), $permission)->where('uuid', $uuid)->firstOrFail();
    }

    // ---- structures -----------------------------------------------------------------------------------------------------

    public function structures(StructureService $svc)
    {
        return SalaryStructure::orderBy('code')->orderByDesc('version')->paginate(25)->through(fn ($s) => $svc->present($s));
    }

    public function showStructure(string $uuid, StructureService $svc)
    {
        return $svc->present(SalaryStructure::byUuid($uuid));
    }

    private function structureRules(): array
    {
        return ['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'country' => 'nullable|regex:/^[A-Z]{2}$/', 'rules' => 'nullable|array',
            'description' => 'nullable|string|max:1000', 'components' => 'required|array|min:1|max:60', 'components.*.component_code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'components.*.sequence' => 'nullable|integer|min:1|max:9999',
            'components.*.required' => 'nullable|boolean', 'components.*.overrides' => 'nullable|array'];
    }

    public function createStructure(Request $r, StructureService $svc)
    {
        $d = $r->validate($this->structureRules() + ['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'name' => 'required|string|max:120']);

        return response()->json($svc->present($svc->create($d, $r->user()->id)), 201);
    }

    public function structureVersion(Request $r, string $uuid, StructureService $svc)
    {
        return response()->json($svc->present($svc->newVersion(SalaryStructure::byUuid($uuid), $r->validate($this->structureRules()), $r->user()->id)), 201);
    }

    public function activateStructure(string $uuid, StructureService $svc)
    {
        return $svc->present($svc->activate(SalaryStructure::byUuid($uuid)));
    }

    // ---- employee compensation -------------------------------------------------------------------------------------------

    public function current(Request $r, string $uuid, CompensationResolverImpl $resolver, CompensationService $svc)
    {
        $d = $r->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        $e = $this->employee($r, $uuid, 'payroll.compensation.view');
        $date = $d['as_of'] ?? now($e->timezone)->toDateString();
        $row = EmployeeCompensation::where('employee_id', $e->id)->where('status', 'approved')->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
        Audit::record('compensation.viewed', 'payroll', $e->uuid, ['surface' => 'current', 'revealed' => $this->reveal($r)]);

        return ['employee_uuid' => $e->uuid, 'as_of' => $date, 'compensation' => $row ? $svc->present($row, $this->reveal($r)) : null];
    }

    public function history(Request $r, string $uuid, CompensationService $svc)
    {
        $e = $this->employee($r, $uuid, 'payroll.compensation.view');
        Audit::record('compensation.viewed', 'payroll', $e->uuid, ['surface' => 'history', 'revealed' => $this->reveal($r)]);

        return ['employee_uuid' => $e->uuid, 'data' => EmployeeCompensation::where('employee_id', $e->id)->orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn ($c) => $svc->present($c, $this->reveal($r)))->all()];
    }

    private function lineRules(): array
    {
        return ['lines' => 'required|array|min:1|max:60', 'lines.*.component_uuid' => 'required|uuid|distinct', 'lines.*.amount' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D']];
    }

    public function propose(Request $r, string $uuid, CompensationService $svc)
    {
        $d = $r->validate(['group_uuid' => 'nullable|uuid', 'structure_uuid' => 'nullable|uuid', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|uuid'] + $this->lineRules());
        $record = $svc->create($r->user(), $d + ['employee_uuid' => $this->employee($r, $uuid, 'payroll.compensation.manage')->uuid, 'source' => 'manual']);

        return response()->json(['uuid' => $record->uuid, 'status' => $record->status], 201);
    }

    public function decideCompensation(Request $r, string $uuid, CompensationService $svc)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return ['uuid' => $svc->decide($r->user(), $uuid, $d['decision'])->uuid];
    }

    // ---- revisions ---------------------------------------------------------------------------------------------------------

    private function revisionRules(): array
    {
        return ['employee_uuid' => 'required|uuid', 'type' => ['required', Rule::in(PayrollStates::REVISION_TYPES)], 'effective_date' => 'required|date_format:Y-m-d', 'structure_uuid' => 'nullable|uuid', 'reason' => 'required|string|max:2000',
            'comments' => 'nullable|string|max:2000', 'letter_reference' => 'nullable|string|max:120'] + $this->lineRules();
    }

    private function revisionBatchRules(): array
    {
        return ['name' => 'required|string|max:160', 'type' => ['required', Rule::in(PayrollStates::REVISION_TYPES)], 'effective_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000',
            'idempotency_key' => 'required|uuid', 'employees' => 'required|array|min:1|max:100', 'employees.*.employee_uuid' => 'required|uuid|distinct', 'employees.*.structure_uuid' => 'nullable|uuid',
            'employees.*.comments' => 'nullable|string|max:2000', 'employees.*.letter_reference' => 'nullable|string|max:120', 'employees.*.lines' => 'required|array|min:1|max:60',
            'employees.*.lines.*.component_uuid' => 'required|uuid', 'employees.*.lines.*.amount' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D']];
    }

    public function previewRevisionBatch(Request $r, RevisionBatchService $svc)
    {
        $result = $svc->preview($r->user(), $r->validate($this->revisionBatchRules()));
        if (! $this->reveal($r)) {
            foreach ($result['rows'] as &$row) {
                if ($row['status'] === 'valid') {
                    $row['impact']['current'] = $row['impact']['proposed'] = $row['impact']['change'] = null;
                    $row['impact']['masked'] = true;
                }
            }
            unset($row);
        }

        return $result;
    }

    public function submitRevisionBatch(Request $r, RevisionBatchService $svc)
    {
        $batch = $svc->submit($r->user(), $r->validate($this->revisionBatchRules()));

        return response()->json($svc->present($batch), 201);
    }

    public function revisionBatches(Request $r, RevisionBatchService $svc)
    {
        return ['data' => $svc->index($r->user())];
    }

    public function showRevisionBatch(Request $r, string $uuid, RevisionBatchService $svc)
    {
        $batch = CompensationRevisionBatch::byUuid($uuid);
        abort_unless($r->user()->allows('payroll.compensation.view'), 403);
        foreach ($batch->revisions()->get() as $revision) {
            abort_unless(app(DataScope::class)->canAccess($r->user(), $revision->employee_id, 'payroll.compensation.view'), 404);
        }
        Audit::record('compensation.viewed', 'payroll', $batch->uuid, ['surface' => 'revision_batch']);

        return $svc->present($batch);
    }

    public function previewCompensationImport(Request $r, CompensationImportService $svc)
    {
        $data = $r->validate(['file' => 'required|file|max:10240', 'type' => ['required', Rule::in(PayrollStates::REVISION_TYPES)], 'effective_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000',
            'mapping' => 'sometimes|array', 'mapping.employee_number' => 'sometimes|nullable|string|max:80', 'mapping.component_code' => 'sometimes|nullable|string|max:80', 'mapping.amount' => 'sometimes|nullable|string|max:80']);
        $result = $svc->preview($r->user(), $data['file'], $data);
        if (! $this->reveal($r) && isset($result['batch_preview']['rows'])) {
            foreach ($result['batch_preview']['rows'] as &$row) {
                if ($row['status'] === 'valid') {
                    $row['impact']['current'] = $row['impact']['proposed'] = $row['impact']['change'] = null;
                    $row['impact']['masked'] = true;
                }
            }
            unset($row);
        }

        return response()->json($result, 201);
    }

    public function commitCompensationImport(Request $r, string $uuid, CompensationImportService $svc)
    {
        return $svc->commit($r->user(), $uuid);
    }

    public function revisions(Request $r, RevisionService $svc)
    {
        $scope = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.compensation.view')->select('employees.id');
        $q = CompensationRevision::whereIn('employee_id', $scope)->with('employee.person')->latest('id');
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }
        if ($r->filled('employee_uuid')) {
            $q->whereIn('employee_id', Employee::where('uuid', $r->query('employee_uuid'))->select('id'));
        }
        Audit::record('compensation.viewed', 'payroll', 'revision-list');

        return $q->paginate(25)->through(fn ($x) => $svc->present($x, $this->reveal($r) || $r->user()->allows('payroll.compensation.approve')));
    }

    public function showRevision(Request $r, string $uuid, RevisionService $svc)
    {
        $rev = CompensationRevision::byUuid($uuid);
        abort_unless(app(DataScope::class)->canAccess($r->user(), $rev->employee_id, 'payroll.compensation.view'), 404);
        Audit::record('compensation.viewed', 'payroll', $rev->uuid, ['surface' => 'revision']);

        return $svc->present($rev, $this->reveal($r) || $r->user()->allows('payroll.compensation.approve'));
    }

    public function previewRevision(Request $r, RevisionService $svc)
    {
        $d = $r->validate($this->revisionRules());
        Audit::record('compensation.preview', 'payroll', $d['employee_uuid']);
        $out = $svc->preview($r->user(), $d);
        if (! $this->reveal($r)) {
            $out['current'] = $out['proposed'] = $out['change'] = null;
            $out['masked'] = true;
        }

        return $out;
    }

    public function proposeRevision(Request $r, RevisionService $svc)
    {
        $d = $r->validate($this->revisionRules() + ['idempotency_key' => 'required|uuid']);
        $rev = $svc->propose($r->user(), $d);

        return response()->json(['uuid' => $rev->uuid, 'status' => $rev->status], 201);
    }

    public function decideRevision(Request $r, string $uuid, RevisionService $svc)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $rev = $svc->decide($r->user(), $uuid, $d['decision']);

        return ['uuid' => $rev->uuid, 'status' => $rev->status];
    }

    public function cancelRevision(Request $r, string $uuid, RevisionService $svc)
    {
        $rev = $svc->cancel($r->user(), $uuid);

        return ['uuid' => $rev->uuid, 'status' => $rev->status];
    }

    public function letter(Request $r, string $uuid)
    {
        $d = $r->validate(['letter_reference' => 'required|string|max:120']);
        $rev = CompensationRevision::byUuid($uuid);
        abort_unless(app(DataScope::class)->canAccess($r->user(), $rev->employee_id, 'payroll.compensation.manage'), 404);
        $rev->update($d);
        Audit::record('revision.letter_linked', 'payroll', $rev->uuid);

        return ['uuid' => $rev->uuid, 'letter_reference' => $rev->letter_reference];
    }
}
