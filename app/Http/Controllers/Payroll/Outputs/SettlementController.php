<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Access\DataScope;
use App\Domain\Payroll\Models\SettlementRun;
use App\Domain\Payroll\Settlement\SettlementService;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SettlementController
{
    private function find(Request $r, string $uuid, string $permission = 'payroll.settlement.manage'): SettlementRun
    {
        $s = SettlementRun::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $s->employee_id, $permission), 404);

        return $s;
    }

    public function index(Request $r, SettlementService $svc)
    {
        $scope = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.settlement.manage')->select('employees.id');

        return SettlementRun::whereIn('employee_id', $scope)->latest('id')->paginate(min(100, (int) $r->query('per_page', 25)))->through(fn ($s) => $svc->present($s));
    }

    public function show(Request $r, string $uuid, SettlementService $svc)
    {
        return $svc->present($this->find($r, $uuid));
    }

    public function store(Request $r, SettlementService $svc)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'last_working_date' => 'required|date_format:Y-m-d', 'currency' => 'required|regex:/^[A-Z]{3}$/', 'group_uuid' => 'nullable|uuid',
            'daily_rate' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'], 'salary_days' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D'], 'notice' => 'nullable|array', 'notice.required_days' => 'required_with:notice|integer|min:0|max:365',
            'notice.served_days' => 'required_with:notice|integer|min:0|max:365', 'include_leave_encashment' => 'nullable|boolean', 'recover_balances' => 'nullable|boolean', 'leave_window_days' => 'nullable|integer|min:30|max:1000',
            'items' => 'nullable|array|max:50', 'items.*.kind' => ['required', Rule::in(SettlementService::KINDS)], 'items.*.direction' => ['required', Rule::in(['pay', 'recover'])],
            'items.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'], 'items.*.explanation' => 'nullable|string|max:400', 'idempotency_key' => 'required|uuid']);

        return response()->json($svc->present($svc->create($r->user(), $d)), 201);
    }

    public function recalculate(Request $r, string $uuid, SettlementService $svc)
    {
        $s = $this->find($r, $uuid);
        $in = $r->validate(['daily_rate' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'], 'salary_days' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D'], 'items' => 'nullable|array|max:50',
            'items.*.kind' => ['required', Rule::in(SettlementService::KINDS)], 'items.*.direction' => ['required', Rule::in(['pay', 'recover'])], 'items.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'], 'items.*.explanation' => 'nullable|string|max:400']);
        $s->update(['inputs' => array_replace($s->inputs ?? [], $in)]);

        return $svc->present($svc->calculate($r->user(), $s));
    }

    public function submit(Request $r, string $uuid, SettlementService $svc)
    {
        return $svc->present($svc->submit($r->user(), $this->find($r, $uuid)));
    }

    public function decide(Request $r, string $uuid, SettlementService $svc)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => 'nullable|string|max:300']);

        return $svc->present($svc->decide($r->user(), $this->find($r, $uuid, 'payroll.settlement.approve'), $d['decision'], $d['note'] ?? null));
    }
}
