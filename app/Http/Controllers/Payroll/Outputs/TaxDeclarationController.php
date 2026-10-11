<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\TaxDeclarations\TaxDeclarationService;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HR review side of tax declarations (permission payroll.tax_declaration.review + data scope). Sensitive values are masked. */
final class TaxDeclarationController
{
    private function find(Request $r, string $uuid): TaxDeclaration
    {
        $d = TaxDeclaration::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $d->employee_id, 'payroll.tax_declaration.review'), 404);

        return $d;
    }

    public function index(Request $r, TaxDeclarationService $svc)
    {
        $scope = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.tax_declaration.review')->select('employees.id');
        $q = TaxDeclaration::whereIn('employee_id', $scope)->latest('id');
        if ($r->query('status')) {
            $q->where('status', (string) $r->query('status'));
        }
        Audit::record('tax_declaration.listed', 'payroll', 'tax-declarations');

        return $q->paginate(min(100, (int) $r->query('per_page', 25)))->through(function ($d) use ($svc) {
            $e = Employee::with('person')->find($d->employee_id);

            return $svc->present($d, false) + ['employee_uuid' => $e->uuid, 'employee_name' => $e->person->display_name];
        });
    }

    public function show(Request $r, string $uuid, TaxDeclarationService $svc)
    {
        $d = $this->find($r, $uuid);
        Audit::record('tax_declaration.viewed', 'payroll', $d->uuid);

        return $svc->present($d);
    }

    public function decide(Request $r, string $uuid, TaxDeclarationService $svc)
    {
        $in = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => 'nullable|string|max:1000', 'proofs' => 'nullable|array|max:50', 'proofs.*.uuid' => 'required|uuid',
            'proofs.*.status' => ['required', Rule::in(['accepted', 'rejected'])], 'proofs.*.accepted_amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'], 'proofs.*.note' => 'nullable|string|max:300']);
        $this->find($r, $uuid);

        return $svc->present($svc->decide($r->user(), $uuid, $in['decision'], $in['note'] ?? null, $in['proofs'] ?? []));
    }

    public function window(Request $r, TaxDeclarationService $svc)
    {
        $d = $r->validate(['pack_uuid' => 'required|uuid', 'tax_year' => 'required|string|max:9', 'opens_on' => 'required|date_format:Y-m-d', 'closes_on' => 'required|date_format:Y-m-d|after_or_equal:opens_on',
            'proof_closes_on' => 'nullable|date_format:Y-m-d|after_or_equal:opens_on', 'locked' => 'nullable|boolean']);
        $w = $svc->openWindow($r->user(), $d);

        return response()->json(['uuid' => $w->uuid, 'tax_year' => $w->tax_year, 'opens_on' => substr((string) $w->opens_on, 0, 10), 'closes_on' => substr((string) $w->closes_on, 0, 10), 'locked' => (bool) $w->locked], 201);
    }
}
