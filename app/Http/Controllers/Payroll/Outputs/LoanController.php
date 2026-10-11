<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeLoan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LoanController
{
    private const MONEY = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'];

    public function index(Request $r)
    {
        $scope = app(DataScope::class)->constrain(\App\Domain\People\Models\Employee::query(), $r->user(), 'payroll.loans.manage')->select('employees.id');
        Audit::record('loan.listed', 'payroll', 'loans');
        $svc = app(LoanService::class);

        return EmployeeLoan::whereIn('employee_id', $scope)->with('employee.person')->latest('id')->paginate(min(100, (int) $r->query('per_page', 25)))->through(fn ($l) => $svc->present($l, false));
    }

    public function show(Request $r, string $uuid)
    {
        $l = EmployeeLoan::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $l->employee_id, 'payroll.loans.manage'), 404);

        return app(LoanService::class)->present($l);
    }

    public function store(Request $r, LoanService $svc)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'principal' => self::MONEY, 'currency' => 'required|regex:/^[A-Z]{3}$/', 'issue_date' => 'required|date_format:Y-m-d', 'recovery_start' => 'required|date_format:Y-m-d',
            'installments' => 'required|integer|min:1|max:120', 'interest' => 'nullable|array', 'interest.type' => ['required_with:interest', Rule::in(['none', 'flat', 'reducing'])], 'interest.rate' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/D'],
            'recovery_method' => ['nullable', Rule::in(['equal', 'custom'])], 'custom_amounts' => 'nullable|array|max:120', 'custom_amounts.*' => ['string', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'], 'purpose' => 'nullable|string|max:200', 'idempotency_key' => 'required|uuid']);

        return response()->json($svc->present($svc->createLoan($r->user(), $d)), 201);
    }

    public function decide(Request $r, string $uuid, LoanService $svc)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => 'nullable|string|max:300']);

        return $svc->present($svc->decideLoan($r->user(), $uuid, $d['decision'], $d['note'] ?? null));
    }

    public function adjust(Request $r, string $uuid, LoanService $svc)
    {
        $d = $r->validate(['entry' => ['required', Rule::in(['waiver', 'write_off'])], 'amount' => self::MONEY, 'note' => 'required|string|max:300']);
        $svc->adjust($r->user(), 'loan', $uuid, $d['entry'], $d['amount'], $d['note']);

        return $svc->present(EmployeeLoan::where('uuid', $uuid)->firstOrFail());
    }

    public function advances(Request $r, LoanService $svc)
    {
        $scope = app(DataScope::class)->constrain(\App\Domain\People\Models\Employee::query(), $r->user(), 'payroll.loans.manage')->select('employees.id');

        return EmployeeAdvance::whereIn('employee_id', $scope)->with('employee.person')->latest('id')->paginate(min(100, (int) $r->query('per_page', 25)))->through(fn ($a) => $svc->presentAdvance($a));
    }

    public function storeAdvance(Request $r, LoanService $svc)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'amount' => self::MONEY, 'currency' => 'required|regex:/^[A-Z]{3}$/', 'issue_date' => 'required|date_format:Y-m-d', 'recovery_start' => 'required|date_format:Y-m-d|after_or_equal:issue_date',
            'recovery_installments' => 'nullable|integer|min:1|max:60', 'reason' => 'nullable|string|max:300', 'idempotency_key' => 'required|uuid']);

        return response()->json($svc->presentAdvance($svc->createAdvance($r->user(), $d)), 201);
    }

    public function decideAdvance(Request $r, string $uuid, LoanService $svc)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => 'nullable|string|max:300']);

        return $svc->presentAdvance($svc->decideAdvance($r->user(), $uuid, $d['decision'], $d['note'] ?? null));
    }
}
