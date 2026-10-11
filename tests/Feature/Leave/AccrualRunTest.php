<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\AccrualRunService;
use App\Domain\Leave\CarryForward\CarryForwardService;
use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Expiry\ConsumptionPlanner;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Locks\DatabaseLeaveLockGuard;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveLock;
use App\Domain\Leave\Support\Units;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AccrualRunTest extends AccrualTestCase
{
    use RefreshDatabase;

    private function available(int $employeeId, string $periodStart = '2026-01-01'): string
    {
        $a = LeaveAccount::query()->where('employee_id', $employeeId)->where('period_start', $periodStart)->firstOrFail();

        return app(BalanceService::class)->balance($a, '2026-10-07')->available;
    }

    public function test_run_is_idempotent_overlap_safe_and_preview_writes_nothing(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'monthly', 'amount' => '2']]);
        $e = $this->hire('Asha Rao', ['joined_on' => '2025-01-01']);
        $runs = app(AccrualRunService::class);
        $params = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        $preview = $runs->preview($params);
        $this->assertSame('preview', $preview->status);
        $this->assertSame(0, LeaveLedgerEntry::query()->count());
        $this->assertSame('2.0000', $preview->items()->first()->getRawOriginal('units') === null ? '' : Units::normalize($preview->items()->first()->getRawOriginal('units')));

        $run = $runs->run($params);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->posted_items);
        $this->assertSame('2.0000', $this->available($e->id));
        $again = $runs->run($params);
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());

        // a different, overlapping window must not credit Sep 30 again but does credit Oct 31 once it is in the window
        $overlap = $runs->run(['from' => '2026-09-15', 'to' => '2026-10-07']);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
        $this->assertSame('already_accrued', $overlap->items()->first()->skip_reason);
        $trace = LeaveLedgerEntry::query()->first()->meta;
        $this->assertSame(['2026-09-30'], $trace['occurrences']);
    }

    public function test_failed_item_is_reported_and_retried(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'monthly', 'amount' => '2']]);
        $a = $this->hire('Asha Rao', ['joined_on' => '2025-01-01']);
        $b = $this->hire('Bela Das', ['joined_on' => '2025-01-01']);
        // Lock Bela's period: her posting fails (423) both on the date and the fallback date -> failed item, Asha unaffected.
        LeaveLock::query()->getModel();
        $this->app->bind(LeaveLockGuard::class, fn () => new class($b->id) implements LeaveLockGuard
        {
            public function __construct(public int $blocked) {}

            public function assertUnlocked(int $employeeId, string $date): void
            {
                if ($employeeId === $this->blocked) {
                    abort(423, 'Period locked.');
                }
            }

            public function isLocked(int $employeeId, string $date): bool
            {
                return $employeeId === $this->blocked;
            }

            public function lockFor(int $employeeId, string $date): ?array
            {
                return null;
            }
        });
        $this->app->forgetInstance(LedgerService::class);
        $this->app->forgetInstance(AccrualRunService::class);
        $runs = app(AccrualRunService::class);
        $run = $runs->run(['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->failed_items);
        $this->assertCount(1, $runs->errors($run, false));

        $this->app->bind(LeaveLockGuard::class, DatabaseLeaveLockGuard::class);
        $this->app->forgetInstance(LedgerService::class);
        $this->app->forgetInstance(AccrualRunService::class);
        $retried = app(AccrualRunService::class)->retry($run->refresh());
        $this->assertSame('completed', $retried->status);
        $this->assertSame(2, LeaveLedgerEntry::query()->count());
        $this->assertSame('2.0000', $this->available($b->id));
    }

    public function test_carry_forward_capped_forfeits_rest_and_closes_account(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10'], 'carry_forward' => ['mode' => 'capped', 'cap' => '4', 'valid_days' => 90]]);
        $e = $this->hire('Asha Rao', ['joined_on' => '2024-01-01']);
        $old = app(AccountService::class)->ensure($e->id, $this->al->id, '2025-06-01');
        app(LedgerService::class)->post(new LedgerPosting($old->id, 'opening', 'opening', '9.5', '2025-01-01', 'seed:old'));
        $carry = app(CarryForwardService::class);

        $preview = $carry->preview(['as_of' => '2026-02-01']);
        $this->assertSame('preview', $preview['items'][0]->status);
        $this->assertSame('4.0000', Units::normalize($preview['items'][0]->getRawOriginal('carried_units')));
        $this->assertSame('open', $old->refresh()->status);

        $res = $carry->run(['as_of' => '2026-02-01']);
        $item = $res['items'][0];
        $this->assertSame('posted', $item->status);
        $this->assertSame('5.5000', Units::normalize($item->getRawOriginal('forfeited_units')));
        $this->assertSame('closed', $old->refresh()->status);
        $new = LeaveAccount::query()->where('employee_id', $e->id)->where('period_start', '2026-01-01')->firstOrFail();
        $bal = app(BalanceService::class)->balance($new, '2026-02-01');
        $this->assertSame('4.0000', $bal->carried);
        $lot = LeaveLedgerEntry::query()->where('account_id', $new->id)->where('entry_type', 'carry_forward_in')->firstOrFail();
        $this->assertSame('2026-03-31', substr((string) $lot->getRawOriginal('expires_on'), 0, 10));
        $this->assertSame('0.0000', app(BalanceService::class)->balance($old, '2026-02-01')->available);
        // idempotent
        $count = LeaveLedgerEntry::query()->count();
        $carry->run(['as_of' => '2026-02-01']);
        $this->assertSame($count, LeaveLedgerEntry::query()->count());
    }

    public function test_negative_balance_is_carried_as_debt_even_when_positive_carry_rule_is_none(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10'], 'carry_forward' => ['mode' => 'none']]);
        $employee = $this->hire('Asha Rao', ['joined_on' => '2024-01-01']);
        $old = app(AccountService::class)->ensure($employee->id, $this->al->id, '2025-06-01');
        $ledger = app(LedgerService::class);
        $ledger->post(new LedgerPosting($old->id, 'opening', 'opening', '5', '2025-01-01', 'negative-carry:opening'));
        $ledger->post(new LedgerPosting($old->id, 'consumption', 'consumed', '-7', '2025-12-31', 'negative-carry:usage'));
        $carry = app(CarryForwardService::class);

        $preview = $carry->preview(['as_of' => '2026-02-01']);
        $this->assertSame('-2.0000', Units::normalize($preview['items'][0]->getRawOriginal('carried_units')));
        $this->assertSame('0.0000', Units::normalize($preview['items'][0]->getRawOriginal('forfeited_units')));
        $result = $carry->run(['as_of' => '2026-02-01']);
        $this->assertSame('posted', $result['items'][0]->status);
        $this->assertSame('closed', $old->refresh()->status);
        $this->assertSame('0.0000', app(BalanceService::class)->balance($old, '2026-02-01')->available);

        $next = LeaveAccount::query()->where('employee_id', $employee->id)->where('period_start', '2026-01-01')->firstOrFail();
        $this->assertSame('-2.0000', app(BalanceService::class)->balance($next, '2026-02-01')->available);
        $debt = LeaveLedgerEntry::query()->where('account_id', $next->id)->where('entry_type', 'carry_forward_in')->firstOrFail();
        $this->assertSame('-2.0000', Units::normalize($debt->getRawOriginal('units')));
        $this->assertNull($debt->expires_on);
    }

    public function test_expiry_consumes_unused_lot_alerts_once_and_planner_orders_lots(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $e = $this->hire('Asha Rao', ['joined_on' => '2024-01-01']);
        $acct = app(AccountService::class)->ensure($e->id, $this->al->id, '2026-06-01');
        $ledger = app(LedgerService::class);
        $a = $ledger->post(new LedgerPosting($acct->id, 'accrual', 'accrued', '5', '2026-01-01', 'seed:a', expiresOn: '2026-10-20'))->entry;
        $b = $ledger->post(new LedgerPosting($acct->id, 'accrual', 'accrued', '5', '2026-02-01', 'seed:b'))->entry;
        $c = $ledger->post(new LedgerPosting($acct->id, 'carry_forward_in', 'carried', '2', '2026-01-01', 'seed:c', expiresOn: '2026-12-31'))->entry;

        $planner = app(ConsumptionPlanner::class);
        $plan = $planner->allocate($acct, '6', '2026-10-08');
        $this->assertSame([$a->id, $c->id], array_column($plan['allocations'], 'source_entry_id')); // earliest-expiring first
        $this->assertSame('5.0000', $plan['allocations'][0]['units']);
        $cur = $planner->allocate($acct, '6', '2026-10-08', 'current_first');
        $this->assertSame([$a->id, $b->id], array_column($cur['allocations'], 'source_entry_id'));
        $carried = $planner->allocate($acct, '3', '2026-10-08', 'carried_first');
        $this->assertSame($c->id, $carried['allocations'][0]['source_entry_id']);

        // alert fires inside the 30-day window once
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
        $expiry = app(ExpiryService::class);
        $this->assertSame(1, $expiry->alerts('2026-10-15')['notified']);
        $this->assertSame(0, $expiry->alerts('2026-10-15')['notified']);
        // use 2 of lot a via an allocated debit, then expire the remaining 3
        $ledger->post(new LedgerPosting($acct->id, 'consumption', 'consumed', '-2', '2026-10-16', 'seed:use', allocations: [['source_entry_id' => $a->id, 'units' => '2']]));
        $res = $expiry->process('2026-10-21');
        $this->assertSame(1, $res['expired']);
        $this->assertSame('3.0000', $res['units']);
        $this->assertSame(0, $expiry->process('2026-10-21')['expired']);
        $this->assertSame('7.0000', app(BalanceService::class)->balance($acct, '2026-10-21')->available);
    }
}
