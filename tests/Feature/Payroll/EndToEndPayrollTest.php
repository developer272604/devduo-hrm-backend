<?php

namespace Tests\Feature\Payroll;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Contracts\PackResolver;
use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\BenefitPlan;
use App\Domain\Payroll\Models\EmployeeBenefitEnrollment;
use App\Domain\Payroll\Models\EmployeeLoan;
use App\Domain\Payroll\Models\LoanInstallment;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Support\Decimal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Real bindings end to end (no fakes): config -> compensation -> pack -> run -> payslips -> payments -> journal -> insight. */
class EndToEndPayrollTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $checker;

    private function api(User $user, string $method, string $path, array $body = [], array $headers = [])
    {
        $this->signIn($user);
        $response = $this->json($method, $path, $body, $headers);
        $this->context($user->tenant_id);

        return $response;
    }

    private function as(User $u, string $m, string $p, array $b = [], array $h = [], ?int $expect = null)
    {
        $r = $this->api($u, $m, '/api/v1/'.$p, $b, $h);
        if ($expect !== null) {
            $r->assertStatus($expect);
        }

        return $r;
    }

    public function test_full_payroll_cycle_with_real_bindings(): void
    {
        $this->bootTenant();
        $admin = $this->admin;
        $this->checker = $checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test', 'Checker');
        Storage::fake('payroll_files');
        config(['filesystems.disks.payroll_files' => ['driver' => 'local', 'root' => Storage::disk('payroll_files')->path(''), 'throw' => true]]);

        $asha = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
        $ravi = $this->hire('Ravi Kumar');

        // ---- A: pay group, calendar, period, pack ------------------------------------------------------------------
        $group = $this->as($admin, 'POST', 'payroll/groups', ['name' => 'ZZ monthly', 'code' => 'ZZ_M', 'legal_entity_id' => $this->org['legal_entity'][0], 'country' => 'ZZ', 'currency' => 'INR',
            'timezone' => 'Asia/Kolkata', 'frequency' => 'monthly'], [], 201)->json('uuid');
        foreach ([$asha, $ravi] as $e) {
            $this->as($admin, 'POST', "payroll/groups/$group/employees", ['employee_uuid' => $e->uuid, 'effective_from' => '2026-01-01'], [], 201);
        }
        $cal = $this->as($admin, 'POST', "payroll/groups/$group/calendars", ['name' => 'FY26', 'frequency' => 'monthly', 'effective_from' => '2026-01-01'], [], 201)->json('uuid');
        $this->as($admin, 'POST', "payroll/calendars/$cal/generate", ['from' => '2026-09-01', 'to' => '2026-09-30'], [], 201);
        $period = collect($this->as($admin, 'GET', "payroll/periods?group_uuid=$group&from=2026-09-01&to=2026-09-30", [], [], 200)->json('data'))->firstWhere('period_start', '2026-09-01');
        $this->assertNotNull($period);
        $periodUuid = $period['uuid'];
        $this->as($admin, 'POST', "payroll/periods/$periodUuid/transition", ['to' => 'input_open'], [], 200);

        $pack = $this->as($admin, 'POST', 'payroll/country-packs/samples/install', [], [], 201)->json();
        $v1 = $pack['versions'][0]['uuid'];
        $this->as($admin, 'PATCH', "payroll/groups/$group", ['country_pack_uuid' => $pack['uuid']], [], 200);
        $this->as($admin, 'POST', "payroll/country-pack-versions/$v1/publish", [], [], 200);
        $this->as($checker, 'POST', "payroll/country-pack-versions/$v1/verify", ['note' => 'Reviewed by independent expert'], [], 200);
        $this->as($checker, 'POST', "payroll/country-packs/{$pack['uuid']}/enable", [], [], 200);

        $comp = fn (string $code, array $o = []) => $this->as($admin, 'POST', 'payroll/components', $o + ['name' => $code, 'code' => $code, 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-01-01'], [], 201)->json('uuid');
        $base = $comp('BASE');
        $hra = $comp('HRA', ['calc_method' => 'percentage', 'config' => ['base_component' => 'BASE', 'percent' => '40']]);
        foreach ([$asha, $ravi] as $e) {
            $c = $this->as($admin, 'POST', "compensation/employees/{$e->uuid}/compensation", ['effective_from' => '2026-01-05', 'reason' => 'Initial', 'idempotency_key' => (string) Str::uuid(),
                'lines' => [['component_uuid' => $base, 'amount' => '50000.00'], ['component_uuid' => $hra]]], [], 201)->json('uuid');
            $this->as($admin, 'POST', "compensation/compensations/$c/decision", ['decision' => 'approved'], [], 403); // maker cannot approve
            $this->as($checker, 'POST', "compensation/compensations/$c/decision", ['decision' => 'approved'], [], 200);
        }

        // Fixed benefit contributions flow into the calculation with effective-date proration.
        $benefitPlan = $this->as($admin, 'POST', 'benefits/plans', ['code' => 'HEALTH_CORE', 'name' => 'Health Plan', 'category' => 'insurance', 'currency' => 'INR',
            'effective_from' => '2026-01-01', 'component_code' => 'HEALTH', 'contributions' => ['employee_fixed' => '300.00', 'employer_fixed' => '600.00']], [], 201)->json('uuid');
        $this->as($admin, 'POST', "benefits/plans/$benefitPlan/activate", [], [], 200);
        $plan = BenefitPlan::where('uuid', $benefitPlan)->firstOrFail();
        EmployeeBenefitEnrollment::create(['employee_id' => $asha->id, 'plan_id' => $plan->id, 'status' => 'active', 'effective_from' => '2026-09-16',
            'employee_contribution' => '300.00', 'employer_contribution' => '600.00', 'created_by' => $admin->id]);
        $resolved = app(CompensationResolver::class)->current($asha->id, '2026-09-30');
        $this->assertSame('70000.00', $resolved['gross_reference']);
        $this->assertNotNull(app(PackResolver::class)->forGroup(DB::table('payroll_groups')->where('uuid', $group)->value('id'), '2026-09-30'));

        // ---- C: loan (maker-checker) that must be recovered by the run -----------------------------------------------------
        $loan = $this->as($admin, 'POST', 'payroll/loans', ['employee_uuid' => $asha->uuid, 'principal' => '3000.00', 'currency' => 'INR', 'issue_date' => '2026-08-01', 'recovery_start' => '2026-09-01',
            'installments' => 3, 'idempotency_key' => (string) Str::uuid()], [], 201)->json('uuid');
        $this->as($checker, 'POST', "payroll/loans/$loan/decision", ['decision' => 'approved'], [], 200)->assertJsonPath('status', 'active');

        // ---- B: inputs, run create -> calculate -> validate -> submit -> approve -> lock -------------------------------------
        $this->as($admin, 'POST', 'payroll/inputs', ['employee_uuid' => $asha->uuid, 'period_uuid' => $periodUuid, 'source' => 'reimbursement', 'kind' => 'reimbursement', 'component_code' => 'TRAVEL', 'amount' => '500.00', 'source_ref' => 'claim-1']);
        $inputUuid = DB::table('payroll_inputs')->value('uuid');
        $this->assertNotNull($inputUuid);
        if (DB::table('payroll_inputs')->where('uuid', $inputUuid)->value('status') === 'pending') {
            $this->as($checker, 'POST', "payroll/inputs/$inputUuid/decision", ['decision' => 'approved'], [], 200);
        }

        $runUuid = $this->as($admin, 'POST', 'payroll/runs', ['period_uuid' => $periodUuid], ['Idempotency-Key' => (string) Str::uuid()], 201)->assertJsonPath('employees_total', 2)->json('uuid');
        $this->as($admin, 'POST', "payroll/runs/$runUuid/collect-inputs", [], [], 200);
        $this->as($admin, 'POST', "payroll/runs/$runUuid/calculate", [], [], 202);
        $progress = $this->as($admin, 'GET', "payroll/runs/$runUuid/progress", [], [], 200)->json();
        $this->assertSame('review', $progress['status'], json_encode($progress));
        $this->assertSame(0, (int) $progress['failed']);

        $ashaResult = $this->as($admin, 'GET', "payroll/runs/$runUuid/results/{$asha->uuid}", [], [], 200)->json('current');
        $codes = collect($ashaResult['components'])->pluck('amount', 'code');
        $this->assertSame('50000.0000', $codes['BASE']);
        $this->assertSame('20000.0000', $codes['HRA']);
        $this->assertSame('1000.0000', $codes['LOAN_RECOVERY'], 'loan installment feeds the run through LoanService::dueFor');
        $this->assertSame('150.0000', $codes['HEALTH_EE'], 'employee contribution is prorated for the 15 covered days');
        $this->assertSame('300.0000', $codes['HEALTH_ER'], 'employer contribution is prorated for the 15 covered days');
        $this->assertSame('500.0000', $codes['TRAVEL']);
        $this->assertSame('70000.0000', $ashaResult['gross']);
        foreach (['gross', 'net'] as $field) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $ashaResult[$field]);
        }
        $ravisCodes = collect($this->as($admin, 'GET', "payroll/runs/$runUuid/results/{$ravi->uuid}", [], [], 200)->json('current.components'))->pluck('code');
        $this->assertFalse($ravisCodes->contains('LOAN_RECOVERY'));

        $verify = $this->as($admin, 'POST', "payroll/runs/$runUuid/results/{$asha->uuid}/verify", [], [], 200)->json();
        $this->assertTrue($verify['reproducible']);
        $this->assertTrue($verify['intact']);
        foreach (PayrollCalculationResult::where('is_current', true)->get() as $r) {
            $this->assertTrue(app(ResultStore::class)->verify($r));
        }
        $this->assertTrue(app()->bound(EmployeeCalculator::class));

        $this->as($admin, 'POST', "payroll/runs/$runUuid/validate", [], [], 200);
        $issues = $this->as($admin, 'GET', "payroll/runs/$runUuid/validations", [], [], 200)->json();
        foreach ($issues['data'] as $i) {
            if (in_array($i['severity'], ['blocking'], true)) {
                $this->as($admin, 'POST', "payroll/validations/{$i['uuid']}/waive", ['note' => 'Accepted for the end-to-end test'], [], 200);
            }
        }
        $this->as($admin, 'POST', "payroll/runs/$runUuid/submit", [], [], 200);
        $this->as($admin, 'POST', "payroll/runs/$runUuid/approve", [], [], 403); // maker cannot approve
        $this->as($checker, 'POST', "payroll/runs/$runUuid/approve", [], [], 200);
        $this->as($checker, 'POST', "payroll/runs/$runUuid/lock", [], [], 200);

        $run = PayrollRun::where('uuid', $runUuid)->first();
        $this->assertSame('locked', $run->status);
        $inst = LoanInstallment::where('sequence', 1)->first();
        $this->assertSame('recovered', $inst->status, 'LoanService::consume ran at lock');
        $this->assertSame('2000.0000', app(LoanService::class)->outstanding('loan', EmployeeLoan::first()->id));

        // reopen releases the recovery, then the cycle is repeated
        $this->as($admin, 'POST', "payroll/runs/$runUuid/reopen-request", ['reason' => 'Late correction to the inputs'], [], 200);
        $this->as($checker, 'POST', "payroll/runs/$runUuid/reopen-decision", ['decision' => 'approved'], [], 200);
        $this->assertSame('scheduled', LoanInstallment::where('sequence', 1)->value('status'), 'LoanService::release ran on reopen');
        $this->assertSame('3000.0000', app(LoanService::class)->outstanding('loan', EmployeeLoan::first()->id));
        $this->as($admin, 'POST', "payroll/runs/$runUuid/calculate", [], [], 202);
        $this->assertSame('review', $this->as($admin, 'GET', "payroll/runs/$runUuid/progress", [], [], 200)->json('status'));
        $this->as($admin, 'POST', "payroll/runs/$runUuid/validate", [], [], 200);
        foreach ($this->as($admin, 'GET', "payroll/runs/$runUuid/validations", [], [], 200)->json('data') as $i) {
            if ($i['severity'] === 'blocking' && $i['status'] === 'open') {
                $this->as($admin, 'POST', "payroll/validations/{$i['uuid']}/waive", ['note' => 'Accepted for the end-to-end test'], [], 200);
            }
        }
        $this->as($admin, 'POST', "payroll/runs/$runUuid/submit", [], [], 200);
        $this->as($checker, 'POST', "payroll/runs/$runUuid/approve", [], [], 200);
        $this->as($checker, 'POST', "payroll/runs/$runUuid/lock", [], [], 200);
        $this->assertSame('recovered', LoanInstallment::where('sequence', 1)->value('status'));
        $this->assertSame(1, DB::table('payroll_loan_ledger')->where('entry_type', 'recovery')->where('reverses_id', null)->whereNotIn('id', DB::table('payroll_loan_ledger')->whereNotNull('reverses_id')->pluck('reverses_id'))->count());

        // ---- C: payslips ---------------------------------------------------------------------------------------------------
        $this->as($admin, 'POST', "payroll/runs/$runUuid/payslips/generate", [], [], 202);
        $this->as($admin, 'POST', "payroll/runs/$runUuid/payslips/publish", [], [], 200)->assertJsonPath('published', 2);
        $me = $asha->person->user;
        $mine = $this->as($me, 'GET', 'payroll/me/payslips', [], [], 200)->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(Decimal4::of($ashaResult['net']), $mine['net']);
        $this->as($me, 'GET', 'payroll/me/payslips/'.$mine['uuid'], [], [], 200)->assertJsonPath('snapshot.totals.gross', '70000.0000');
        $this->as($ravi->person->user ?? $checker, 'GET', 'payroll/me/payslips/'.$mine['uuid'], [], [], 404);

        // ---- C: payments (maker-checker) advance the run through B's state machine --------------------------------------------
        DB::table('employee_bank_accounts')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $admin->tenant_id, 'person_id' => $asha->person_id, 'account_holder' => 'Asha Rao', 'bank_name' => 'Test Bank',
            'country' => 'ZZ', 'currency' => 'INR', 'details_encrypted' => Crypt::encryptString(json_encode(['account_number' => '123456789012', 'routing' => 'TEST0001'])), 'account_last4' => '9012',
            'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->as($admin, 'POST', 'payroll/bank-verifications', ['employee_uuid' => $asha->uuid], [], 200);
        $batch = $this->as($admin, 'POST', "payroll/runs/$runUuid/payments", ['idempotency_key' => (string) Str::uuid()], [], 201)->json();
        $this->assertSame(Decimal4::of($ashaResult['net']), $batch['total_amount']);
        $this->assertSame('0.0000', $batch['reconciliation']['difference']);
        $this->as($admin, 'POST', "payroll/payments/{$batch['uuid']}/approve", [], [], 403);
        $this->as($checker, 'POST', "payroll/payments/{$batch['uuid']}/approve", [], [], 200);
        $this->as($checker, 'POST', "payroll/payments/{$batch['uuid']}/bank-file", [], [], 201);
        $this->as($checker, 'POST', "payroll/payments/{$batch['uuid']}/submit", [], [], 200);
        $this->assertSame('payment', PayrollRun::where('uuid', $runUuid)->value('status'));
        $ref = $this->as($checker, 'GET', "payroll/payments/{$batch['uuid']}", [], [], 200)->json('instructions.0.reference');
        $this->as($checker, 'POST', "payroll/payments/{$batch['uuid']}/confirm", ['updates' => [['reference' => $ref, 'status' => 'paid', 'bank_reference' => 'BANK-1']]], [], 200)->assertJsonPath('batch.status', 'paid');
        $this->assertSame('closed', PayrollRun::where('uuid', $runUuid)->value('status'));

        // ---- C: accounting journal balances --------------------------------------------------------------------------------
        foreach (['earning' => ['6000', '2100'], 'reimbursement' => ['6100', '2100'], 'deduction' => ['2100', '2200'], 'tax' => ['2100', '2300'], 'employee_contribution' => ['2100', '2400'], 'employer_contribution' => ['6200', '2400']] as $kind => [$d, $c]) {
            $this->as($admin, 'POST', 'payroll/accounting/mappings', ['kind' => $kind, 'debit_account' => $d, 'credit_account' => $c, 'effective_from' => '2026-01-01'], [], 201);
        }
        $journal = $this->as($admin, 'GET', "payroll/runs/$runUuid/journal/preview?posting_date=2026-09-30", [], [], 200)->json();
        $this->assertTrue($journal['balanced']);
        $this->assertSame($journal['total_debit'], $journal['total_credit']);

        // ---- D: readiness, explainer on real stored results -----------------------------------------------------------------
        $this->as($admin, 'GET', "payroll/readiness?group=$group&period=$periodUuid", [], [], 200)->assertJsonStructure(['state', 'checks']);
        $this->as($admin, 'GET', 'payroll/command-center', [], [], 200);
        $ex = $this->as($admin, 'GET', "payroll/explain/employees/{$asha->uuid}?run=$runUuid&purpose_code=payroll_processing", [], [], 200)->json();
        $this->assertTrue($ex['reconciles']);
        $this->assertTrue($ex['reproducible']);
        $this->assertSame(Decimal4::of($ashaResult['net']), Decimal4::of($ex['totals']['net']), 'explainer reports the currency exponent (2dp), equal in value to the stored 4dp net');

        // ---- tenant isolation ----------------------------------------------------------------------------------------------
        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->as($beta, 'GET', "payroll/runs/$runUuid", [], [], 404);
        $this->as($beta, 'GET', "payroll/runs/$runUuid/results/{$asha->uuid}", [], [], 404);
        $this->as($beta, 'GET', "payroll/payments/{$batch['uuid']}", [], [], 404);
    }
}

/** Tiny helper: canonical 4dp decimal string. */
final class Decimal4
{
    public static function of(string $v): string
    {
        return Decimal::str($v, 4);
    }
}
