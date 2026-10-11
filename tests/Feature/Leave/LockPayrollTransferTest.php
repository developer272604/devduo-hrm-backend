<?php

namespace Tests\Feature\Leave;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveTransfer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\LeaveOpsFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class LockPayrollTransferTest extends TestCase
{
    use LeaveOpsFixtures, PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    public function test_lock_blocks_ledger_and_unlock_needs_a_second_person(): void
    {
        $a = $this->hire('Asha Rao');
        $type = $this->leaveType('AL');
        $acct = $this->leaveAccount($a, $type);
        $this->signIn($this->admin);
        $body = ['scope_type' => 'tenant', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'reason' => 'Sept closed'];
        $this->postJson('/api/v1/leave/locks/preview', $body)->assertOk()->assertJsonPath('can_lock', true);
        $uuid = $this->postJson('/api/v1/leave/locks', $body)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/leave/locks', $body)->assertStatus(422);
        $this->context($this->admin->tenant_id);
        try {
            $this->credit($acct, '5', 'k-locked', '2026-09-10');
            $this->fail('expected lock');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }
        $this->postJson("/api/v1/leave/locks/$uuid/unlock-request", ['reason' => 'late fix'])->assertOk();
        $this->postJson("/api/v1/leave/locks/$uuid/unlock-approve")->assertStatus(422); // same person
        $this->context($this->admin->tenant_id);
        $checker = $this->secondAdmin();
        $this->signIn($checker);
        $this->postJson("/api/v1/leave/locks/$uuid/unlock-approve")->assertOk()->assertJsonPath('data.status', 'unlocked');
        $this->context($this->admin->tenant_id);
        $this->credit($acct, '5', 'k-open', '2026-09-10');
        $this->assertSame('5.0000', app(BalanceService::class)->balance($acct, '2026-10-01')->available);
    }

    public function test_locks_need_permission(): void
    {
        $m = $this->managerUser();
        $this->signIn($m)->getJson('/api/v1/leave/locks')->assertForbidden();
        $this->postJson('/api/v1/leave/payroll/contract/batches', ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertForbidden();
    }

    public function test_payroll_contract_has_units_references_and_no_money(): void
    {
        $a = $this->hire('Asha Rao');
        $al = $this->leaveType('AL');
        $unpaid = $this->leaveType('UL', ['category' => 'unpaid']);
        $this->approvedLeave($a, $al, ['2026-09-10', '2026-09-11'], ['decided_at' => '2026-09-05 10:00:00']);
        $this->approvedLeave($a, $unpaid, ['2026-09-20'], ['pay_class' => 'unpaid', 'decided_at' => '2026-10-06 10:00:00']);
        DB::table('leave_settlement_references')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $a->tenant_id, 'employee_id' => $a->id, 'leave_type_id' => $al->id, 'kind' => 'encashment',
            'units' => '2', 'status' => 'approved', 'as_of' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($this->admin);
        $this->putJson('/api/v1/attendance/settings/cutoffs', ['payroll_cutoff_day' => 3]);
        $res = $this->getJson('/api/v1/leave/payroll/contract?from=2026-09-01&to=2026-09-30')->assertOk()->json();
        $this->assertSame('2.0000', $res['totals']['paid']);
        $this->assertSame('1.0000', $res['totals']['unpaid']);
        $this->assertSame('2.0000', $res['totals']['encashment']);
        $this->assertNull($res['money']);
        $this->assertStringNotContainsString('amount', json_encode($res['lines']));
        $key = $this->postJson('/api/v1/leave/payroll/contract/batches', ['from' => '2026-09-01', 'to' => '2026-09-30', 'batch_key' => 'sep-2026'])->assertCreated()->json('batch_key');
        $this->assertSame(3, DB::table('leave_payroll_lines')->where('batch_key', $key)->count());
        $this->getJson("/api/v1/leave/payroll/contract/batches/$key")->assertOk()->assertJsonPath('totals.unpaid', '1.0000');
        $this->getJson('/api/v1/leave/payroll/contract/batches/none')->assertNotFound();
    }

    public function test_separation_encashes_and_expires_balances(): void
    {
        $a = $this->hire('Asha Rao');
        $al = $this->leaveType('AL', ['config' => ['encashment' => ['on_separation' => true, 'max_units' => '4']]]);
        $acct = $this->leaveAccount($a, $al);
        $this->credit($acct, '10', 'open1', '2026-01-01');
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/leave/transfers/preview', ['employee' => $a->uuid, 'trigger' => 'separation', 'effective_date' => '2026-10-07'])->assertCreated()->json('data.uuid');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, LeaveLedgerEntry::query()->where('entry_type', 'encashment')->count()); // preview posts nothing
        $this->postJson("/api/v1/leave/transfers/$uuid/apply")->assertOk()->assertJsonPath('data.status', 'applied');
        $this->postJson("/api/v1/leave/transfers/$uuid/apply")->assertOk(); // idempotent
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, LeaveLedgerEntry::query()->where('entry_type', 'encashment')->count());
        $this->assertSame('0.0000', app(BalanceService::class)->balance($acct, '2026-10-07')->available);
        $this->assertSame(1, DB::table('leave_settlement_references')->where('employee_id', $a->id)->count());
    }

    public function test_rehire_restores_prior_employment_balance(): void
    {
        $previous = $this->hire('Asha Rao');
        $rehired = $this->hire('Asha Rao Rehired');
        $al = $this->leaveType('AL', ['config' => ['rehire' => ['restore' => 'all']]]);
        $oldAccount = $this->leaveAccount($previous, $al);
        $this->credit($oldAccount, '6', 'rehire:source', '2026-01-01');
        $this->signIn($this->admin);

        $transfer = $this->postJson('/api/v1/leave/transfers/preview', ['employee' => $rehired->uuid, 'trigger' => 'rehire', 'previous_employee' => $previous->uuid, 'effective_date' => '2026-10-07'])
            ->assertCreated()->assertJsonPath('data.plan.items.0.restore.units', '6.0000')->json('data.uuid');
        $this->postJson("/api/v1/leave/transfers/$transfer/apply")->assertOk()->assertJsonPath('data.status', 'applied');
        $this->context($this->admin->tenant_id);

        $restored = LeaveAccount::query()->where('employee_id', $rehired->id)->where('leave_type_id', $al->id)->firstOrFail();
        $this->assertSame('0.0000', app(BalanceService::class)->balance($oldAccount, '2026-10-07')->available);
        $this->assertSame('6.0000', app(BalanceService::class)->balance($restored, '2026-10-07')->available);
    }

    public function test_people_transfer_event_creates_a_leave_preview_without_applying_it(): void
    {
        $employee = $this->hire('Asha Rao');
        $tenant = Tenant::findOrFail($employee->tenant_id);
        $settings = $tenant->settings ?? [];
        $settings['modules'] = ['leave'];
        $tenant->settings = $settings;
        $tenant->save();
        $this->context($employee->tenant_id);

        app(DomainEvents::class)->record('EmployeeTransferred', ['employee' => $employee, 'summary' => 'Employee transferred', 'effective_date' => '2026-10-07', 'payload' => ['fields' => ['country']]]);

        $preview = LeaveTransfer::query()->where('employee_id', $employee->id)->where('trigger', 'transfer')->firstOrFail();
        $this->assertSame('preview', $preview->status);
        $this->assertSame('2026-10-07', substr((string) $preview->getRawOriginal('effective_date'), 0, 10));
        $this->assertSame(0, LeaveLedgerEntry::query()->count(), 'People events prepare a preview but never post ledger entries.');
    }

    public function test_convert_uses_mapping_and_preserve_posts_nothing(): void
    {
        $a = $this->hire('Asha Rao');
        $x = $this->leaveType('OLD');
        $y = $this->leaveType('NEW');
        $acct = $this->leaveAccount($a, $x);
        $this->credit($acct, '6', 'o', '2026-01-01');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/leave/type-mappings', ['from' => 'OLD', 'to' => 'NEW', 'ratio' => '0.5', 'purpose' => 'conversion'])->assertCreated();
        $p = $this->postJson('/api/v1/leave/transfers/preview', ['employee' => $a->uuid, 'trigger' => 'policy_change', 'effective_date' => '2026-10-07', 'balance_mode' => 'preserve'])->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/leave/transfers/$p/apply")->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
        $c = $this->postJson('/api/v1/leave/transfers/preview', ['employee' => $a->uuid, 'trigger' => 'transfer', 'effective_date' => '2026-10-08', 'balance_mode' => 'convert'])->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/leave/transfers/$c/apply")->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame('0.0000', app(BalanceService::class)->balance($acct, '2026-10-08')->available);
        $target = LeaveAccount::query()->where('leave_type_id', $y->id)->firstOrFail();
        $this->assertSame('3.0000', app(BalanceService::class)->balance($target, '2026-10-08')->available);
    }

    public function test_reports_mask_sensitive_and_cross_tenant_is_hidden(): void
    {
        $a = $this->hire('Asha Rao');
        $s = $this->leaveType('SICK', ['is_sensitive' => true]);
        $this->approvedLeave($a, $s, ['2026-09-10']);
        $this->signIn($this->admin);
        $rows = $this->getJson('/api/v1/leave/reports/requests')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('reason', $rows[0]);
        $this->getJson('/api/v1/leave/reports/nope')->assertNotFound();
        $this->getJson('/api/v1/leave/command-center')->assertOk()->assertJsonPath('away_today.count', 0);
        $this->getJson('/api/v1/leave/explain/balance?employee='.$a->uuid)->assertOk();
        $this->getJson('/api/v1/leave/confidence?employee='.$a->uuid)->assertOk()->assertJsonPath('level', 'confirmed');
        $this->getJson('/api/v1/leave/time-story?employee='.$a->uuid)->assertOk();
        $this->postJson('/api/v1/leave/reports/requests/export', ['format' => 'csv'])->assertStatus(202);
        $other = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($other)->getJson('/api/v1/leave/explain/balance?employee='.$a->uuid)->assertNotFound();
    }

    public function test_simulation_writes_only_a_run(): void
    {
        $a = $this->hire('Asha Rao');
        $al = $this->leaveType('AL');
        $this->leaveAccount($a, $al);
        $this->signIn($this->admin);
        $before = [DB::table('leave_ledger_entries')->count(), DB::table('leave_accounts')->count(), DB::table('leave_policy_versions')->count()];
        $res = $this->postJson('/api/v1/leave/simulations/sandbox', ['leave_type_id' => $al->id, 'employees' => [$a->uuid], 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'rules' => ['entitlement' => ['amount' => '12'], 'carry_forward' => ['mode' => 'capped', 'cap' => '5']]])->assertOk()->assertJsonPath('read_only', true)->json();
        $this->assertSame('12.0000', $res['rows'][0]['draft_entitlement']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before, [DB::table('leave_ledger_entries')->count(), DB::table('leave_accounts')->count(), DB::table('leave_policy_versions')->count()]);
        $this->assertSame(1, DB::table('leave_simulation_runs')->count());
    }
}
