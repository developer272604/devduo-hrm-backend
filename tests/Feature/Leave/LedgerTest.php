<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerRejected;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveLock;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private Employee $employee;

    private LeaveType $type;

    private LeaveAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->employee = $this->hire('Asha Rao');
        $this->type = LeaveType::create(['name' => 'Annual leave', 'code' => 'AL', 'kind' => 'annual']);
        $this->account = $this->account($this->employee, $this->type);
    }

    private function account(Employee $employee, LeaveType $type, string $negativeLimit = '0'): LeaveAccount
    {
        return LeaveAccount::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'negative_limit' => $negativeLimit]);
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function credit(string $units, string $key, string $bucket = 'accrued', string $type = 'accrual', string $date = '2026-10-01', ?string $expires = null, ?LeaveAccount $account = null)
    {
        return $this->ledger()->post(new LedgerPosting(($account ?? $this->account)->id, $type, $bucket, $units, $date, $key, expiresOn: $expires));
    }

    public function test_services_and_lock_guard_are_bound_and_permissions_are_registered(): void
    {
        $this->assertInstanceOf(LedgerService::class, app(LedgerService::class));
        $this->assertInstanceOf(LeaveLockGuard::class, app(LeaveLockGuard::class));
        foreach (['leave.view', 'leave.request', 'leave.team.view', 'leave.approve', 'leave.manage', 'leave.policy.manage', 'leave.adjust', 'leave.accrual.run', 'leave.import', 'leave.lock', 'leave.reports.view', 'leave.simulate', 'leave.medical.view', 'leave.compoff.manage'] as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
        $this->assertSame(480, LeaveSettings::get('units.minutes_per_day'));
        $this->assertContains('leave', $this->admin->tenant->refresh()->settings['modules']);
    }

    public function test_leave_routes_are_module_gated_and_empty(): void
    {
        $this->signIn($this->admin)->getJson('/api/v1/leave/anything')->assertNotFound();
    }

    public function test_posting_is_idempotent_and_never_writes_twice(): void
    {
        $first = $this->credit('12', 'accrual:2026-10');
        $second = $this->credit('12', 'accrual:2026-10');
        $this->assertFalse($first->duplicate);
        $this->assertTrue($second->duplicate);
        $this->assertSame($first->entry->id, $second->entry->id);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
        $this->assertSame('12.0000', app(BalanceService::class)->balance($this->account)->available);
    }

    public function test_reusing_an_idempotency_key_for_a_different_posting_is_a_conflict(): void
    {
        $this->credit('12', 'k1');
        try {
            $this->credit('5', 'k1');
            $this->fail('Expected conflict');
        } catch (LedgerRejected $e) {
            $this->assertSame('idempotency_conflict', $e->reason);
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
    }

    public function test_entries_are_immutable(): void
    {
        $entry = $this->credit('3', 'imm')->entry;
        try {
            $entry->update(['units' => '99']);
            $this->fail('update must be refused');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_reversal_posts_the_opposite_entry_once_and_reversals_are_not_reversible(): void
    {
        $credit = $this->credit('10', 'c1')->entry;
        $reversal = $this->ledger()->reverse($credit, 'c1:rev', 'Posted in error');
        $this->assertSame('-10.0000', Units::normalize($reversal->entry->getRawOriginal('units')));
        $this->assertSame($credit->id, $reversal->entry->reverses_entry_id);
        $this->assertSame('0.0000', app(BalanceService::class)->balance($this->account)->available);
        $this->assertTrue($this->ledger()->reverse($credit, 'c1:rev')->duplicate);
        try {
            $this->ledger()->reverse($credit, 'c1:rev2');
            $this->fail('second reversal');
        } catch (LedgerRejected $e) {
            $this->assertSame('already_reversed', $e->reason);
        }
        try {
            $this->ledger()->reverse($reversal->entry, 'rev-of-rev');
            $this->fail('reversal of reversal');
        } catch (LedgerRejected $e) {
            $this->assertSame('not_reversible', $e->reason);
        }
        $this->assertSame(2, LeaveLedgerEntry::query()->count());
    }

    public function test_cannot_reverse_a_credit_that_has_been_spent(): void
    {
        $credit = $this->credit('2', 'c')->entry;
        $this->ledger()->reserve($this->account->id, '2', 'r', '2026-10-05');
        $this->expectException(LedgerRejected::class);
        $this->ledger()->reverse($credit, 'c:rev');
    }

    public function test_balance_buckets_and_available_are_derived_from_the_ledger(): void
    {
        $b = app(BalanceService::class);
        $this->credit('5', 'open', 'opening', 'opening');
        $this->credit('12', 'acc');
        $this->credit('3', 'carry', 'carried', 'carry_forward_in', '2026-01-01', '2026-03-31');
        $this->credit('1.5', 'adj', 'adjusted', 'adjustment');
        $reserve = $this->ledger()->reserve($this->account->id, '2', 'res1', '2026-10-06', null, 'trip')->entry;
        $this->ledger()->commitReservation($reserve, 'scheduled', '2026-10-06', 'commit1');
        $this->ledger()->reserve($this->account->id, '1', 'res2', '2026-10-07');
        $this->ledger()->post(new LedgerPosting($this->account->id, 'consumption', 'consumed', '-4', '2026-09-01', 'used'));
        $this->ledger()->post(new LedgerPosting($this->account->id, 'expiry', 'expired', '-0.5', '2026-10-01', 'exp', allowClosed: true));
        $this->ledger()->post(new LedgerPosting($this->account->id, 'encashment', 'encashed', '-1', '2026-10-02', 'enc'));

        $bal = $b->balance($this->account, '2026-10-08');
        $this->assertSame('5.0000', $bal->opening);
        $this->assertSame('12.0000', $bal->accrued);
        $this->assertSame('3.0000', $bal->carried);
        $this->assertSame('1.5000', $bal->adjusted);
        $this->assertSame('4.0000', $bal->consumed);
        $this->assertSame('1.0000', $bal->pending);
        $this->assertSame('2.0000', $bal->scheduled);
        $this->assertSame('0.5000', $bal->expired);
        $this->assertSame('1.0000', $bal->encashed);
        $this->assertSame('21.5000', $bal->entitled);
        $this->assertSame('13.0000', $bal->available); // 21.5 - 4 - 1 - 2 - 0.5 - 1
        $this->assertNotEmpty($bal->trace['accrued']);
        $this->assertTrue($b->verify($this->account)['consistent']);
        $statement = $b->statement($this->account);
        $this->assertSame('13.0000', end($statement)['running_available']);
    }

    public function test_commit_reservation_moves_pending_to_scheduled_then_consumed_without_touching_history(): void
    {
        $b = app(BalanceService::class);
        $this->credit('10', 'c');
        $res = $this->ledger()->reserve($this->account->id, '3', 'r', '2026-10-05')->entry;
        $this->assertSame('3.0000', $b->balance($this->account)->pending);
        $parts = $this->ledger()->commitReservation($res, 'scheduled', '2026-10-05', 'k');
        $this->assertTrue($this->ledger()->commitReservation($res, 'scheduled', '2026-10-05', 'k')['consumption']->duplicate);
        $bal = $b->balance($this->account);
        $this->assertSame(['0.0000', '3.0000', '7.0000'], [$bal->pending, $bal->scheduled, $bal->available]);
        $this->ledger()->settleScheduled($parts['consumption']->entry, '2026-10-12', 'settle');
        $bal = $b->balance($this->account);
        $this->assertSame(['0.0000', '0.0000', '3.0000', '7.0000'], [$bal->pending, $bal->scheduled, $bal->consumed, $bal->available]);
        $this->assertSame(1, LeaveLedgerEntry::query()->where('idempotency_key', 'r')->count());
    }

    public function test_decimal_precision_is_exact(): void
    {
        $b = app(BalanceService::class);
        for ($i = 1; $i <= 10; $i++) {
            $this->credit('0.1', "tenth:$i");
        }
        $this->credit('0.0001', 'tiny');
        $this->assertSame('1.0001', $b->balance($this->account)->available); // floats would give 1.0001000000000002
        $this->assertSame('0.3000', Units::add('0.1', '0.2'));
        $this->assertSame('0.3333', Units::div('1', '3'));
        $this->assertSame('0.6667', Units::div('2', '3'));
        $this->assertSame('0.3750', Units::mul('0.75', '0.5'));
        $this->assertSame('-1.5000', Units::neg('1.5'));
        $this->assertTrue($b->verify($this->account)['consistent']);
        foreach (['0.00001', 'abc', '1e3'] as $bad) {
            try {
                $this->credit($bad, 'bad:'.$bad);
                $this->fail("accepted $bad");
            } catch (LedgerRejected $e) {
                $this->assertSame('invalid_posting', $e->reason);
            }
        }
        $this->expectException(LedgerRejected::class);
        $this->credit('0', 'zero');
    }

    public function test_reservations_cannot_overdraw_and_retries_do_not_double_reserve(): void
    {
        $b = app(BalanceService::class);
        $this->credit('5', 'c');
        $a = $this->ledger()->reserve($this->account->id, '3', 'req1', '2026-10-05');
        $this->assertTrue($this->ledger()->reserve($this->account->id, '3', 'req1', '2026-10-05')->duplicate);
        try {
            $this->ledger()->reserve($this->account->id, '3', 'req2', '2026-10-06');
            $this->fail('second reservation must overdraw');
        } catch (LedgerRejected $e) {
            $this->assertSame('insufficient_balance', $e->reason);
        }
        $this->ledger()->reserve($this->account->id, '2', 'req3', '2026-10-06');
        $this->assertSame('0.0000', $b->balance($this->account)->available);
        $this->assertSame('5.0000', $b->balance($this->account)->pending);
        // Releasing the first hold frees the units for someone else.
        $this->ledger()->reverse($a->entry, 'req1:release');
        $this->assertSame('3.0000', $b->balance($this->account)->available);
        $this->ledger()->reserve($this->account->id, '3', 'req2', '2026-10-06');
        $this->assertSame('0.0000', $b->balance($this->account)->available);
    }

    public function test_negative_limit_allows_borrowing_within_the_limit_only(): void
    {
        $account = $this->account($this->hire('Bina Rao'), $this->type, '2');
        $this->credit('1', 'c', account: $account);
        $this->ledger()->reserve($account->id, '3', 'r1', '2026-10-05'); // 1 - 3 = -2: exactly the limit
        $this->assertSame('-2.0000', app(BalanceService::class)->balance($account)->available);
        $this->expectException(LedgerRejected::class);
        $this->ledger()->reserve($account->id, '0.0001', 'r2', '2026-10-05');
    }

    public function test_future_dated_credits_do_not_count_as_usable_now(): void
    {
        $this->credit('10', 'future', date: '2026-12-01');
        $b = app(BalanceService::class);
        $this->assertSame('0.0000', $b->balance($this->account)->available);
        $this->assertSame('10.0000', $b->balance($this->account, '2026-12-01', true)->available);
        $this->expectException(LedgerRejected::class);
        $this->ledger()->reserve($this->account->id, '1', 'r', '2026-10-07');
    }

    public function test_reversing_a_future_dated_grant_nets_to_zero(): void
    {
        $grant = $this->credit('5', 'fg', date: '2026-12-01')->entry;
        $this->ledger()->reverse($grant, 'fg:rev');
        $this->assertSame('0.0000', app(BalanceService::class)->balance($this->account)->available);
        $this->assertSame('0.0000', app(BalanceService::class)->balance($this->account, '2027-01-01', true)->available);
    }

    public function test_lots_follow_consumption_order_and_track_allocations(): void
    {
        $b = app(BalanceService::class);
        $late = $this->credit('4', 'late', 'accrued', 'accrual', '2026-01-01', '2026-12-31')->entry;
        $soon = $this->credit('2', 'soon', 'carried', 'carry_forward_in', '2026-01-01', '2026-03-31')->entry;
        $none = $this->credit('1', 'none', 'opening', 'opening', '2026-01-01')->entry;
        $this->assertSame([$soon->id, $late->id, $none->id], array_column($b->lots($this->account), 'entry_id'));
        $use = $this->ledger()->post(new LedgerPosting($this->account->id, 'consumption', 'consumed', '-3', '2026-10-05', 'use', allocations: [
            ['source_entry_id' => $soon->id, 'units' => '2'], ['source_entry_id' => $late->id, 'units' => '1']]));
        $lots = collect($b->lots($this->account))->keyBy('entry_id');
        $this->assertFalse($lots->has($soon->id));
        $this->assertSame('3.0000', $lots[$late->id]['remaining']);
        $this->ledger()->reverse($use->entry, 'use:rev'); // reversal releases the lots again
        $this->assertSame('2.0000', collect($b->lots($this->account))->keyBy('entry_id')[$soon->id]['remaining']);
        $this->expectException(LedgerRejected::class);
        $this->ledger()->post(new LedgerPosting($this->account->id, 'consumption', 'consumed', '-9', '2026-10-05', 'over', allocations: [['source_entry_id' => $none->id, 'units' => '9']]));
    }

    public function test_locked_period_blocks_postings_unless_system_override(): void
    {
        LeaveLock::create(['scope_type' => 'tenant', 'scope_id' => '*', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'locked', 'reason' => 'September payroll', 'locked_by' => $this->admin->id, 'locked_at' => now()]);
        $this->assertTrue(app(LeaveLockGuard::class)->isLocked($this->employee->id, '2026-09-15'));
        try {
            $this->credit('1', 'locked', date: '2026-09-15');
            $this->fail('should be locked');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
            $this->assertStringContainsString('September payroll', $e->getMessage());
        }
        $this->assertSame(0, LeaveLedgerEntry::query()->count());
        $this->ledger()->post(new LedgerPosting($this->account->id, 'expiry', 'expired', '-1', '2026-09-15', 'sys', ignoreLock: true));
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
        $this->credit('1', 'open-period', date: '2026-10-01');
    }

    public function test_closed_accounts_refuse_new_postings(): void
    {
        $this->account->update(['status' => 'closed']);
        $this->expectException(LedgerRejected::class);
        $this->credit('1', 'x');
    }

    public function test_tenant_isolation_for_accounts_entries_and_idempotency_keys(): void
    {
        $this->credit('9', 'shared-key');
        $alphaAccount = $this->account->id;
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->context($beta->tenant_id);

        $this->assertSame(0, LeaveLedgerEntry::query()->count());
        try {
            $this->ledger()->post(new LedgerPosting($alphaAccount, 'accrual', 'accrued', '1', '2026-10-01', 'beta-try'));
            $this->fail('another tenant account must be invisible');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        // The same idempotency key is independent per tenant.
        $betaType = LeaveType::create(['name' => 'Annual', 'code' => 'AL', 'kind' => 'annual']);
        $this->buildOrganization();
        $betaEmployee = $this->hire('Chen Li');
        $betaAccount = $this->account($betaEmployee, $betaType);
        $this->assertFalse($this->ledger()->post(new LedgerPosting($betaAccount->id, 'accrual', 'accrued', '1', '2026-10-01', 'shared-key'))->duplicate);
        $this->assertSame('1.0000', app(BalanceService::class)->balance($betaAccount)->available);

        $this->context($this->admin->tenant_id);
        $this->assertSame('9.0000', app(BalanceService::class)->balance($this->account)->available);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
    }
}
