<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Models\EmployeeLoan;
use App\Domain\Payroll\Models\PaymentBatch;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Support\PayrollSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PaymentAccountingSettlementTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    private $run;

    private $employee;

    private $checker;

    private $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->group = $this->payrollGroup();
        $this->run = $this->payrollRun($this->group, $this->payrollPeriod($this->group), ['status' => 'review']);
        $this->employee = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
        $re = $this->runEmployee($this->run, $this->employee);
        app(ResultStore::class)->record(new ResultDraft($re->id, 'INR', $this->sampleComponents(), PayrollSettings::ENGINE_VERSION, '30', '30'));
        $this->run->update(['status' => 'locked']);
        Storage::fake('payroll_files');
        config(['filesystems.disks.payroll_files' => ['driver' => 'local', 'root' => Storage::disk('payroll_files')->path(''), 'throw' => true]]);
    }

    private function bank(): void
    {
        DB::table('employee_bank_accounts')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'person_id' => $this->employee->person_id, 'account_holder' => 'Asha Rao', 'bank_name' => 'Test Bank',
            'country' => 'IN', 'currency' => 'INR', 'details_encrypted' => Crypt::encryptString(json_encode(['account_number' => '123456789012', 'routing' => 'TEST0001'])), 'account_last4' => '9012',
            'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_payment_batch_maker_checker_file_confirmation_and_failure_handling(): void
    {
        $this->bank();
        $run = '/api/v1/payroll/runs/'.$this->run->uuid.'/payments';
        $key = (string) Str::uuid();
        $this->signIn($this->admin)->postJson($run, ['idempotency_key' => $key])->assertUnprocessable(); // bank not verified
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/bank-verifications', ['employee_uuid' => $this->employee->uuid])->assertOk()->assertJsonPath('account', '****9012');
        $this->context($this->admin->tenant_id);
        $uuid = $this->postJson($run, ['idempotency_key' => $key2 = (string) Str::uuid()])->assertCreated()->assertJsonPath('total_amount', '62899.5000')->assertJsonPath('reconciliation.difference', '0.0000')
            ->assertJsonPath('instructions.0.account', '********9012')->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->postJson($run, ['idempotency_key' => $key2])->assertCreated()->assertJsonPath('uuid', $uuid);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, PaymentBatch::count());
        $this->assertNotSame('123456789012', DB::table('payment_instructions')->value('beneficiary'));

        $this->postJson("/api/v1/payroll/payments/$uuid/approve")->assertForbidden(); // maker cannot approve
        $this->postJson("/api/v1/payroll/payments/$uuid/bank-file")->assertUnprocessable(); // not approved
        $this->signIn($this->checker)->postJson("/api/v1/payroll/payments/$uuid/approve")->assertOk()->assertJsonPath('approved', true);
        $this->context($this->checker->tenant_id);
        $file = $this->postJson("/api/v1/payroll/payments/$uuid/bank-file")->assertCreated()->json();
        $this->assertTrue(Storage::disk('payroll_files')->exists(DB::table('bank_file_exports')->value('file_path')));
        $this->get($file['download'])->assertOk();
        $this->postJson("/api/v1/payroll/payments/$uuid/confirm", ['updates' => [['reference' => 'x', 'status' => 'paid']]])->assertStatus(409); // not submitted yet
        $this->postJson("/api/v1/payroll/payments/$uuid/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $ref = $this->getJson("/api/v1/payroll/payments/$uuid")->json('instructions.0.reference');

        $r = $this->postJson("/api/v1/payroll/payments/$uuid/confirm", ['updates' => [['reference' => $ref, 'status' => 'paid']]])->assertOk();
        $this->assertSame('rejected', $r->json('results.0.result')); // no bank reference
        $this->postJson("/api/v1/payroll/payments/$uuid/confirm", ['updates' => [['reference' => $ref, 'status' => 'failed', 'reason' => 'Account closed']]])->assertOk()->assertJsonPath('batch.status', 'failed');
        $retry = $this->postJson("/api/v1/payroll/payments/$uuid/retry", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('status', 'prepared')->assertJsonPath('retry_of_id', $uuid)->json('uuid');
        $this->signIn($this->admin)->postJson("/api/v1/payroll/payments/$retry/approve")->assertOk();
        $this->context($this->admin->tenant_id);
        $this->postJson("/api/v1/payroll/payments/$retry/bank-file")->assertCreated();
        $this->postJson("/api/v1/payroll/payments/$retry/submit")->assertOk();
        $ref2 = $this->getJson("/api/v1/payroll/payments/$retry")->json('instructions.0.reference');
        $this->postJson("/api/v1/payroll/payments/$retry/confirm", ['updates' => [['reference' => $ref2, 'status' => 'paid', 'bank_reference' => 'BANK-1']]])->assertOk()->assertJsonPath('batch.status', 'paid');
        $this->assertSame('failed', DB::table('payment_instructions')->where('reference', $ref)->value('status'), 'history of the failed attempt is kept');
    }

    public function test_journal_blocks_on_unmapped_balances_and_exports_versioned_files(): void
    {
        $base = '/api/v1/payroll/runs/'.$this->run->uuid.'/journal';
        $this->signIn($this->admin)->getJson($base.'/preview')->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        foreach (['earning' => ['6000', '2100'], 'reimbursement' => ['6100', '2100'], 'deduction' => ['2100', '2200'], 'tax' => ['2100', '2300'], 'employee_contribution' => ['2100', '2400'], 'employer_contribution' => ['6200', '2400']] as $kind => [$d, $c]) {
            $this->postJson('/api/v1/payroll/accounting/mappings', ['kind' => $kind, 'debit_account' => $d, 'credit_account' => $c, 'effective_from' => '2026-01-01'])->assertCreated();
        }
        $this->context($this->admin->tenant_id);
        $preview = $this->getJson($base.'/preview?posting_date=2026-09-30')->assertOk()->assertJsonPath('balanced', true)->json();
        $this->assertSame($preview['total_debit'], $preview['total_credit']);
        $this->assertSame('62899.5000', $preview['reconciliation']['net_total']);
        $e1 = $this->postJson($base.'/export', ['posting_date' => '2026-09-30'])->assertCreated()->assertJsonPath('version', 1)->json();
        $this->postJson($base.'/export', ['posting_date' => '2026-09-30'])->assertCreated()->assertJsonPath('version', 2);
        $this->get($e1['download'])->assertOk();
        $this->assertSame(0, DB::table('accounting_exports')->whereRaw('total_debit != total_credit')->count());
    }

    public function test_settlement_is_itemised_maker_checker_and_stored_through_result_store(): void
    {
        $loan = $this->signIn($this->admin)->postJson('/api/v1/payroll/loans', ['employee_uuid' => $this->employee->uuid, 'principal' => '3000.00', 'currency' => 'INR', 'issue_date' => '2026-08-01', 'recovery_start' => '2026-09-01',
            'installments' => 3, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->signIn($this->checker)->postJson("/api/v1/payroll/loans/$loan/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'active');
        $this->context($this->checker->tenant_id);
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/payroll/settlements', ['employee_uuid' => $this->employee->uuid, 'last_working_date' => '2026-09-20', 'currency' => 'INR', 'group_uuid' => $this->group->uuid, 'daily_rate' => '1000.00',
            'salary_days' => '20', 'notice' => ['required_days' => 30, 'served_days' => 25], 'items' => [['kind' => 'bonus', 'direction' => 'pay', 'amount' => '500.00', 'explanation' => 'Pro-rata bonus']],
            'idempotency_key' => $k = (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        // pay: 20*1000 + 500 = 20500 ; recover: notice 5*1000 + loan 3000 = 8000 ; net 12500
        $s = $this->getJson("/api/v1/payroll/settlements/$uuid")->assertOk()->assertJsonPath('net_payable', '12500.0000')->assertJsonPath('summary.recover', '8000.00')->json();
        $this->assertCount(4, $s['items']);
        $this->assertNotEmpty($s['items'][0]['explanation']);
        $this->postJson("/api/v1/payroll/settlements/$uuid/submit")->assertOk()->assertJsonPath('status', 'review');
        $this->postJson("/api/v1/payroll/settlements/$uuid/decision", ['decision' => 'approved'])->assertForbidden();
        $this->signIn($this->checker)->postJson("/api/v1/payroll/settlements/$uuid/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->context($this->checker->tenant_id);
        $r = PayrollCalculationResult::whereHas('run', fn ($q) => $q->where('run_type', 'settlement'))->first();
        $this->assertSame('12500.0000', $r->net);
        $this->assertSame('locked', $r->run->status);
        $this->assertSame('0.0000', app(\App\Domain\Payroll\Loans\LoanService::class)->outstanding('loan', EmployeeLoan::first()->id));
        $this->assertSame('closed', EmployeeLoan::first()->status);
    }
}
