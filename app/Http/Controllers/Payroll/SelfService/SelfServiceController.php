<?php

namespace App\Http\Controllers\Payroll\SelfService;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\CountryPack;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeLoan;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Payslips\PayslipService;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\TaxDeclarations\TaxDeclarationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** Employee self-service: the employee is ALWAYS resolved from the signed-in user, never from request input. Other people's records are 404. */
final class SelfServiceController
{
    private function own(Request $r, string $uuid): Payslip
    {
        $e = Own::employee($r->user());

        return Payslip::where('uuid', $uuid)->where('employee_id', $e->id)->whereIn('status', ['published', 'superseded'])->whereNotNull('published_at')->firstOrFail();
    }

    public function payslips(Request $r, PayslipService $svc)
    {
        $e = Own::employee($r->user());

        return Payslip::where('employee_id', $e->id)->whereIn('status', ['published', 'superseded'])->whereNotNull('published_at')->orderByDesc('period_id')->orderByDesc('version')
            ->paginate(min(100, (int) $r->query('per_page', 24)))->through(fn ($p) => $svc->summary($p));
    }

    public function payslip(Request $r, string $uuid, PayslipService $svc)
    {
        $p = $this->own($r, $uuid);
        $svc->log($p, $r->user(), 'viewed');
        if (! $p->first_viewed_at) {
            $p->update(['first_viewed_at' => now()]);
        }

        return $svc->summary($p, true);
    }

    public function print(Request $r, string $uuid, PayslipService $svc)
    {
        $p = $this->own($r, $uuid);
        $svc->log($p, $r->user(), 'viewed');

        return response($svc->html($p), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }

    public function downloadLink(Request $r, string $uuid, PayslipService $svc)
    {
        $p = $this->own($r, $uuid);
        $minutes = max(1, min(60, (int) PayrollSettings::get('payslips.download_link_minutes', 10)));
        $expires = now()->addMinutes($minutes);
        $svc->log($p, $r->user(), 'link_issued');

        return ['url' => URL::temporarySignedRoute('payroll.me.payslip.download', $expires, ['uuid' => $p->uuid], absolute: false), 'expires_at' => $expires->toIso8601String()];
    }

    /** Signed + authenticated + owner only. */
    public function download(Request $r, string $uuid, PayslipService $svc)
    {
        $p = $this->own($r, $uuid);
        $p->increment('download_count');
        $svc->log($p, $r->user(), 'downloaded');

        return response($svc->html($p), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="payslip-'.$p->snapshot['period']['label'].'-v'.$p->version.'.html"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function compensation(Request $r)
    {
        $e = Own::employee($r->user());
        Audit::record('compensation.viewed_own', 'payroll', $e->uuid);
        if (! app()->bound(CompensationResolver::class)) {
            return ['available' => false, 'compensation' => null];
        }
        $c = app(CompensationResolver::class)->current($e->id, now()->toDateString());
        if (! $c) {
            return ['available' => true, 'compensation' => null];
        }

        return ['available' => true, 'compensation' => ['currency' => $c['currency'] ?? null, 'effective_from' => $c['effective_from'] ?? null, 'effective_to' => $c['effective_to'] ?? null, 'pay_frequency' => $c['pay_frequency'] ?? null,
            'base_pay' => $c['base_pay'] ?? null, 'gross_reference' => $c['gross_reference'] ?? null, 'annual_compensation' => $c['annual_compensation'] ?? null,
            'components' => array_map(fn ($l) => ['code' => $l['component_code'], 'name' => $l['name'], 'kind' => $l['kind'], 'amount' => $l['amount']], $c['lines'] ?? [])]];
    }

    public function loans(Request $r, LoanService $svc)
    {
        $e = Own::employee($r->user());
        Audit::record('loan.viewed_own', 'payroll', $e->uuid);

        return ['loans' => EmployeeLoan::where('employee_id', $e->id)->latest('id')->get()->map(fn ($l) => $svc->present($l))->all(),
            'advances' => EmployeeAdvance::where('employee_id', $e->id)->latest('id')->get()->map(fn ($a) => $svc->presentAdvance($a))->all()];
    }

    public function taxPacks(Request $r)
    {
        Own::employee($r->user());
        $rows = DB::table('payroll_tax_declaration_windows as w')->join('payroll_country_packs as p', 'p.id', '=', 'w.pack_id')->where('w.tenant_id', Own::tid())->orderByDesc('w.opens_on')
            ->get(['p.uuid as pack_uuid', 'p.name', 'p.country', 'w.tax_year', 'w.opens_on', 'w.closes_on', 'w.proof_closes_on', 'w.locked']);
        $today = now()->toDateString();

        return ['data' => $rows->map(fn ($w) => ['pack_uuid' => $w->pack_uuid, 'name' => $w->name, 'country' => $w->country, 'tax_year' => $w->tax_year, 'opens_on' => substr((string) $w->opens_on, 0, 10),
            'closes_on' => substr((string) $w->closes_on, 0, 10), 'open' => ! $w->locked && $today >= substr((string) $w->opens_on, 0, 10) && $today <= substr((string) $w->closes_on, 0, 10)])];
    }

    public function taxSchema(Request $r, TaxDeclarationService $svc)
    {
        Own::employee($r->user());
        $d = $r->validate(['pack_uuid' => 'required|uuid']);

        return $svc->schemaFor($d['pack_uuid']);
    }

    public function declarations(Request $r, TaxDeclarationService $svc)
    {
        $e = Own::employee($r->user());

        return ['data' => TaxDeclaration::where('employee_id', $e->id)->latest('id')->get()->map(fn ($d) => $svc->present($d))->all()];
    }

    public function submitDeclaration(Request $r, TaxDeclarationService $svc)
    {
        $d = $r->validate(['pack_uuid' => 'required|uuid', 'tax_year' => 'required|string|max:9', 'regime' => 'nullable|string|max:40', 'data' => 'required|array|max:100']);

        return response()->json($svc->present($svc->submit($r->user(), $d)), 201);
    }

    public function addProof(Request $r, string $uuid, TaxDeclarationService $svc)
    {
        $e = Own::employee($r->user());
        $decl = TaxDeclaration::where('uuid', $uuid)->where('employee_id', $e->id)->firstOrFail();
        $d = $r->validate(['category' => 'required|string|max:60', 'declared_amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'], 'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $svc->addProof($r->user(), $decl, $d, $r->file('file'));

        return response()->json($svc->present($decl->refresh()), 201);
    }
}
