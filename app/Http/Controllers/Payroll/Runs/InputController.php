<?php

namespace App\Http\Controllers\Payroll\Runs;

use App\Domain\Access\DataScope;
use App\Domain\Payroll\Inputs\InputImportService;
use App\Domain\Payroll\Inputs\InputService;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollInput;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Runs\RunService;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payroll inputs (manual / API variable pay) and adjustments (arrears, recoveries). */
final class InputController
{
    public function __construct(private InputService $inputs, private RunService $runs) {}

    public function index(Request $r)
    {
        $allowed = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.inputs.manage')->select('employees.id');
        $q = PayrollInput::with('employee.person')->whereIn('employee_id', $allowed)->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($r->query('period_uuid'), fn ($q, $u) => $q->whereIn('period_id', PayrollPeriod::where('uuid', $u)->select('id')))->orderByDesc('id');

        return $q->paginate(min((int) $r->query('per_page', 50), 100))->through(fn ($i) => $this->inputs->present($i));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'period_uuid' => 'required|uuid', 'source' => ['required', Rule::in(InputService::MANUAL_SOURCES)], 'kind' => 'required|string|max:32',
            'component_code' => 'nullable|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'quantity' => 'nullable|string|max:24', 'amount' => 'nullable|string|max:24', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'source_ref' => 'nullable|string|max:200', 'meta' => 'nullable|array']);
        $input = $this->inputs->create($r->user(), $d);

        return response()->json($this->inputs->present($input), $input->wasRecentlyCreated ? 201 : 200);
    }

    public function previewImport(Request $r, InputImportService $imports)
    {
        $data = $r->validate(['file' => 'required|file|max:10240', 'period_uuid' => 'required|uuid', 'source' => ['required', Rule::in(InputService::MANUAL_SOURCES)],
            'mapping' => 'sometimes|array', 'mapping.employee_number' => 'sometimes|nullable|string|max:80', 'mapping.kind' => 'sometimes|nullable|string|max:80', 'mapping.component_code' => 'sometimes|nullable|string|max:80',
            'mapping.amount' => 'sometimes|nullable|string|max:80', 'mapping.quantity' => 'sometimes|nullable|string|max:80']);

        return response()->json($imports->preview($r->user(), $data['file'], $data), 201);
    }

    public function commitImport(Request $r, string $uuid, InputImportService $imports)
    {
        return $imports->commit($r->user(), $uuid);
    }

    public function retryImport(Request $r, string $uuid, InputImportService $imports)
    {
        return $imports->retry($r->user(), $uuid);
    }

    public function importHistory(Request $r, InputImportService $imports)
    {
        return ['data' => $imports->history($r->user())];
    }

    public function showImport(Request $r, string $uuid, InputImportService $imports)
    {
        return $imports->show($r->user(), $uuid);
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required_if:decision,rejected|nullable|string|max:400']);

        return $this->inputs->present($this->inputs->decide($r->user(), $uuid, $d['decision'], $d['reason'] ?? null));
    }

    public function void(Request $r, string $uuid)
    {
        return $this->inputs->present($this->inputs->void($r->user(), $uuid));
    }

    public function adjustments(Request $r)
    {
        $allowed = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.adjust')->select('employees.id');

        return PayrollAdjustment::with('employee.person')->whereIn('employee_id', $allowed)->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(min((int) $r->query('per_page', 50), 100))
            ->through(fn ($a) => $this->presentAdjustment($a));
    }

    public function storeAdjustment(Request $r)
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[0-9a-f-]{36}$/i', $key), 422, 'An Idempotency-Key header (UUID) is required.');
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'kind' => ['required', Rule::in(['arrear', 'retro', 'correction', 'manual_net', 'recovery'])], 'settlement_period_uuid' => 'required|uuid', 'origin_period_uuid' => 'nullable|uuid',
            'component_code' => 'nullable|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'amount' => ['required', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'], 'reason' => 'required|string|min:5|max:1000', 'trace' => 'nullable|array']);

        return response()->json($this->presentAdjustment($this->runs->createAdjustment($r->user(), $d, $key)), 201);
    }

    public function decideAdjustment(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return $this->presentAdjustment($this->runs->decideAdjustment($r->user(), $uuid, $d['decision']));
    }

    public function reverseAdjustment(Request $r, string $uuid)
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[0-9a-f-]{36}$/i', $key), 422, 'An Idempotency-Key header (UUID) is required.');
        $data = $r->validate(['settlement_period_uuid' => 'required|uuid', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->presentAdjustment($this->runs->reverseAdjustment($r->user(), $uuid, $data['settlement_period_uuid'], $data['reason'], $key)), 201);
    }

    /** @return array<string,mixed> */
    private function presentAdjustment(PayrollAdjustment $a): array
    {
        $a->loadMissing('employee.person');

        return ['uuid' => $a->uuid, 'employee_uuid' => $a->employee->uuid, 'employee_name' => $a->employee->person->display_name, 'kind' => $a->kind, 'status' => $a->status, 'component_code' => $a->component_code,
            'amount' => Decimal::str($a->amount, 4), 'currency' => $a->currency, 'reason' => $a->reason, 'trace' => $a->trace, 'reverses_uuid' => $a->reverses_id ? PayrollAdjustment::query()->whereKey($a->reverses_id)->value('uuid') : null];
    }
}
