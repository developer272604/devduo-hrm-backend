<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\CountryPack;
use App\Domain\Payroll\Models\CountryPackVersion;
use App\Domain\Payroll\Models\EmployeeLoan;
use App\Domain\Payroll\Models\LoanInstallment;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class LoanTaxDeclarationTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    private $employee;

    private $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->employee = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
    }

    public function test_schedule_math_is_exact(): void
    {
        $svc = app(LoanService::class);
        $equal = $svc->schedule('10000.00', 'INR', 3, ['type' => 'none']);
        $this->assertSame(['3333.34', '3333.33', '3333.33'], array_column($equal, 'amount'));
        $reducing = $svc->schedule('12000.00', 'INR', 12, ['type' => 'reducing', 'rate' => '12']);
        $this->assertCount(12, $reducing);
        $this->assertSame('12000.00', (string) Decimal::str(Decimal::sum(array_column($reducing, 'principal')), 2));
        $this->assertSame('1066.19', Decimal::str(Decimal::add($reducing[0]['principal'], $reducing[0]['interest']), 2));
        $flat = $svc->schedule('1200.00', 'INR', 12, ['type' => 'flat', 'rate' => '10']);
        $this->assertSame('120.00', (string) Decimal::str(Decimal::sum(array_column($flat, 'interest')), 2));
    }

    public function test_loan_lifecycle_recovery_and_reversal_are_ledger_based(): void
    {
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/payroll/loans', ['employee_uuid' => $this->employee->uuid, 'principal' => '3000.00', 'currency' => 'INR', 'issue_date' => '2026-08-01', 'recovery_start' => '2026-09-30',
            'installments' => 3, 'idempotency_key' => $k = (string) Str::uuid()])->assertCreated()->assertJsonPath('outstanding', '3000.0000')->assertJsonCount(3, 'schedule')->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/loans', ['employee_uuid' => $this->employee->uuid, 'principal' => '3000.00', 'currency' => 'INR', 'issue_date' => '2026-08-01', 'recovery_start' => '2026-09-30',
            'installments' => 3, 'idempotency_key' => $k])->assertCreated()->assertJsonPath('uuid', $uuid);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, EmployeeLoan::count());
        $this->postJson("/api/v1/payroll/loans/$uuid/decision", ['decision' => 'approved'])->assertForbidden();
        $this->signIn($this->checker)->postJson("/api/v1/payroll/loans/$uuid/decision", ['decision' => 'approved'])->assertOk();
        $this->context($this->checker->tenant_id);

        $group = $this->payrollGroup();
        $run = $this->payrollRun($group, $this->payrollPeriod($group, '2026-09-01', '2026-09-30'), ['status' => 'locked']);
        $this->runEmployee($run, $this->employee);
        $svc = app(LoanService::class);
        $this->assertCount(1, $svc->dueFor($this->employee->id, '2026-09-30'));
        $this->assertSame(1, $svc->consume($run));
        $this->assertSame(0, $svc->consume($run), 'idempotent');
        $loan = EmployeeLoan::first();
        $this->assertSame('2000.0000', $svc->outstanding('loan', $loan->id));
        $this->assertSame('recovered', LoanInstallment::orderBy('sequence')->first()->status);
        $this->assertSame(0, count($svc->dueFor($this->employee->id, '2026-09-30')));
        $this->assertSame(1, $svc->release($run));
        $this->assertSame('3000.0000', $svc->outstanding('loan', $loan->id));
        $this->assertSame(2, DB::table('payroll_loan_ledger')->count(), 'history kept: recovery + reversal');
        $this->assertSame('scheduled', LoanInstallment::orderBy('sequence')->first()->status);

        $this->signIn($this->employee->person->user)->getJson('/api/v1/payroll/me/loans')->assertOk()->assertJsonPath('loans.0.outstanding', '3000.0000');
        $this->signIn($this->checker)->postJson("/api/v1/payroll/loans/$uuid/adjust", ['entry' => 'waiver', 'amount' => '3000.00', 'note' => 'Goodwill'])->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_tax_declaration_window_submit_review_masking_and_isolation(): void
    {
        $pack = CountryPack::create(['code' => 'GEN', 'country' => 'IN', 'name' => 'Generic', 'handler' => 'declarative', 'status' => 'enabled']);
        CountryPackVersion::create(['pack_id' => $pack->id, 'version' => 1, 'status' => 'published', 'effective_from' => '2026-01-01', 'declaration_schema' => ['fields' => [
            ['key' => 'tax_id', 'label' => 'Tax ID', 'type' => 'string', 'required' => true, 'sensitive' => true, 'pattern' => '^[A-Z0-9]{6,12}$'],
            ['key' => 'rent_paid', 'label' => 'Rent paid', 'type' => 'decimal', 'required' => false]]]]);
        $me = $this->employee->person->user;
        $body = ['pack_uuid' => $pack->uuid, 'tax_year' => '2026-27', 'data' => ['tax_id' => 'ABCDE1234F', 'rent_paid' => '120000.00']];

        $this->signIn($me)->postJson('/api/v1/payroll/me/tax-declarations', $body)->assertUnprocessable(); // no window
        $this->signIn($this->admin)->postJson('/api/v1/payroll/tax-declaration-windows', ['pack_uuid' => $pack->uuid, 'tax_year' => '2026-27', 'opens_on' => '2026-10-01', 'closes_on' => '2026-10-31'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->signIn($me)->postJson('/api/v1/payroll/me/tax-declarations', ['data' => ['tax_id' => 'bad'] ] + $body)->assertUnprocessable();
        $this->context($me->tenant_id);
        $d = $this->postJson('/api/v1/payroll/me/tax-declarations', $body)->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('data.tax_id', '******234F')->json();
        $this->context($me->tenant_id);
        $this->assertStringNotContainsString('ABCDE1234F', (string) DB::table('payroll_tax_declarations')->value('data'));
        $this->postJson("/api/v1/payroll/me/tax-declarations/{$d['uuid']}/proofs", ['category' => 'rent', 'declared_amount' => '120000.00'])->assertCreated()->assertJsonCount(1, 'proofs');
        $this->getJson('/api/v1/payroll/tax-declarations')->assertForbidden();

        $this->signIn($this->checker)->getJson('/api/v1/payroll/tax-declarations/'.$d['uuid'])->assertOk()->assertJsonPath('data.tax_id', '******234F');
        $this->context($this->checker->tenant_id);
        $proof = $this->getJson('/api/v1/payroll/tax-declarations/'.$d['uuid'])->json('proofs.0.uuid');
        $this->postJson('/api/v1/payroll/tax-declarations/'.$d['uuid'].'/decision', ['decision' => 'approved', 'proofs' => [['uuid' => $proof, 'status' => 'accepted', 'accepted_amount' => '130000.00']]])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/tax-declarations/'.$d['uuid'].'/decision', ['decision' => 'approved', 'proofs' => [['uuid' => $proof, 'status' => 'accepted', 'accepted_amount' => '100000.00']]])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('proofs.0.accepted_amount', '100000.00');
        $this->context($this->checker->tenant_id);
        $this->assertTrue((bool) TaxDeclaration::first()->locked);
        $this->signIn($me)->postJson('/api/v1/payroll/me/tax-declarations', $body)->assertUnprocessable(); // locked after approval
    }
}
