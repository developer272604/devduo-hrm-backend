<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AccrualApiTest extends AccrualTestCase
{
    use RefreshDatabase;

    private function ledgerCount(): int
    {
        $this->context($this->admin->tenant_id);

        return LeaveLedgerEntry::query()->count();
    }

    private function firstEntry(): LeaveLedgerEntry
    {
        $this->context($this->admin->tenant_id);

        return LeaveLedgerEntry::query()->firstOrFail();
    }

    public function test_balances_ledger_trace_and_data_scope(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $manager = $this->hire('Mia Boss', ['role' => 'Manager', 'email' => 'mia@alpha.test']);
        $asha = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test', 'manager' => $manager]);
        $other = $this->hire('Omar Else', ['role' => 'Team member', 'email' => 'omar@alpha.test']);
        $acct = app(AccountService::class)->ensure($asha->id, $this->al->id, '2026-06-01');
        $entry = app(LedgerService::class)->post(new LedgerPosting($acct->id, 'accrual', 'accrued', '10', '2026-01-01', 'seed:1', meta: ['basis' => 'annual', 'occurrences' => ['2026-01-01']]))->entry;
        $ashaUser = \App\Models\User::query()->where('email', 'asha@alpha.test')->first();

        $res = $this->signIn($ashaUser)->getJson('/api/v1/leave/me/balances?include=trace')->assertOk();
        $this->assertSame('10.0000', $res->json('data.0.balance.available'));
        $this->assertSame([$entry->uuid], $res->json('data.0.trace.accrued'));
        $accountId = $res->json('data.0.account.id');
        $ledger = $this->getJson("/api/v1/leave/accounts/$accountId/ledger")->assertOk();
        $this->assertTrue($ledger->json('data.integrity.consistent'));
        $this->assertSame('10.0000', $ledger->json('data.entries.0.running_available'));
        $this->getJson("/api/v1/leave/ledger/entries/$entry->uuid")->assertOk()->assertJsonPath('data.calculation.basis', 'annual');

        // a colleague cannot read Asha's data; the manager can (team scope)
        $otherUser = \App\Models\User::query()->where('email', 'omar@alpha.test')->first();
        $this->signIn($otherUser)->getJson("/api/v1/leave/accounts/$accountId")->assertNotFound();
        $this->getJson("/api/v1/leave/employees/$asha->uuid/balances")->assertNotFound();
        $mia = \App\Models\User::query()->where('email', 'mia@alpha.test')->first();
        $this->signIn($mia)->getJson("/api/v1/leave/employees/$asha->uuid/balances")->assertOk()->assertJsonPath('data.0.balance.available', '10.0000');
        $team = $this->getJson('/api/v1/leave/team/balances')->assertOk();
        $names = array_column(array_column($team->json('data'), 'employee'), 'name');
        $this->assertContains('Asha Rao', $names);
        $this->assertNotContains('Omar Else', $names);
        $this->signIn($ashaUser)->getJson('/api/v1/leave/team/balances')->assertForbidden();

        // medical leave type hidden from the manager
        $this->context($this->admin->tenant_id);
        $sick = LeaveType::create(['name' => 'Medical', 'code' => 'MED', 'kind' => 'medical', 'is_sensitive' => true]);
        $m = app(AccountService::class)->ensure($asha->id, $sick->id, '2026-06-01');
        app(LedgerService::class)->post(new LedgerPosting($m->id, 'opening', 'opening', '5', '2026-01-01', 'seed:med'));
        $this->assertCount(2, $this->signIn($ashaUser)->getJson('/api/v1/leave/me/balances')->json('data'));
        $this->assertCount(1, $this->signIn($mia)->getJson("/api/v1/leave/employees/$asha->uuid/balances")->json('data'));
    }

    public function test_tenant_isolation_returns_404(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $e = $this->hire('Asha Rao');
        $acct = app(AccountService::class)->ensure($e->id, $this->al->id, '2026-06-01');
        $uuid = $acct->uuid;
        $this->context(null);
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta)->getJson("/api/v1/leave/accounts/$uuid")->assertNotFound();
    }

    public function test_accrual_run_endpoints_preview_run_duplicate_and_queue(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'monthly', 'amount' => '2']]);
        $e = $this->hire('Asha Rao', ['joined_on' => '2025-01-01']);
        $this->signIn($this->admin);
        $body = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $p = $this->postJson('/api/v1/leave/accrual-runs/preview', $body)->assertOk();
        $this->assertSame('preview', $p->json('data.status'));
        $this->assertSame(0, $this->ledgerCount());
        $this->getJson('/api/v1/leave/accrual-runs/'.$p->json('data.id').'/items')->assertOk()->assertJsonPath('data.0.units', '2.0000');
        $this->postJson('/api/v1/leave/accrual-runs', $body)->assertCreated()->assertJsonPath('data.counts.posted', 1);
        $this->postJson('/api/v1/leave/accrual-runs', $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, $this->ledgerCount());
        $this->postJson('/api/v1/leave/accrual-runs', ['from' => '2026-08-01', 'to' => '2026-08-31', 'queue' => true])->assertStatus(202); // sync queue in tests => completed
        $this->assertSame(2, $this->ledgerCount());
        $this->postJson('/api/v1/leave/entitlements/calculate', ['employee' => $e->uuid, 'leave_type' => $this->al->uuid, 'from' => '2026-01-01', 'to' => '2026-12-31'])->assertOk()->assertJsonPath('data.units', '24.0000');
        $this->artisan('leave:accrue-nightly --sync')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->signIn($this->userWithRole('Team member', 'tm@alpha.test'))->postJson('/api/v1/leave/accrual-runs', $body)->assertForbidden();
    }

    public function test_adjustments_enforce_maker_checker_and_bulk_preview(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $e = $this->hire('Asha Rao');
        $f = $this->hire('Bela Das');
        $checker = $this->userWithRole('HR administrator', 'hr2@alpha.test', 'HR Two');
        $this->signIn($this->admin);
        $req = $this->postJson('/api/v1/leave/adjustments', ['employee' => $e->uuid, 'leave_type' => $this->al->uuid, 'units' => '3', 'kind' => 'grant', 'reason' => 'Goodwill', 'effective_date' => '2026-10-01'])->assertCreated();
        $id = $req->json('data.id');
        $this->assertDatabaseHas('work_items', ['assignee_id' => $checker->id, 'source_module' => 'leave', 'source_entity' => 'leave_adjustment:'.$id]);
        $this->assertSame(0, $this->ledgerCount());
        $this->postJson("/api/v1/leave/adjustments/$id/approve")->assertForbidden();
        $this->assertSame(0, $this->ledgerCount());
        $ok = $this->signIn($checker)->postJson("/api/v1/leave/adjustments/$id/approve", ['note' => 'ok'])->assertOk();
        $this->assertSame('posted', $ok->json('data.status'));
        $this->postJson("/api/v1/leave/adjustments/$id/approve")->assertStatus(409);
        $entry = $this->firstEntry();
        $this->assertSame('adjusted', $entry->bucket);
        $this->assertSame('3.0000', \App\Domain\Leave\Support\Units::normalize($entry->getRawOriginal('units')));

        // a debit larger than the balance is refused at preview
        $prev = $this->postJson('/api/v1/leave/adjustments/bulk/preview', ['employees' => [$e->uuid, $f->uuid], 'leave_type' => $this->al->uuid, 'units' => '5', 'kind' => 'debit', 'reason' => 'Correction'])->assertOk();
        $this->assertSame(1, $prev->json('data.summary.errors'));
        $this->assertSame(1, $prev->json('data.summary.will_apply'));
        $this->signIn($this->admin);
        $bulk = $this->postJson('/api/v1/leave/adjustments/bulk', ['employees' => [$e->uuid, $f->uuid], 'leave_type' => $this->al->uuid, 'units' => '1', 'kind' => 'grant', 'reason' => 'Bonus day', 'idempotency_key' => 'k1'])->assertCreated();
        $this->assertSame(2, $bulk->json('data.created'));
        $key = $bulk->json('data.bulk_key');
        $this->postJson('/api/v1/leave/adjustments/bulk', ['employees' => [$e->uuid, $f->uuid], 'leave_type' => $this->al->uuid, 'units' => '1', 'kind' => 'grant', 'reason' => 'Bonus day', 'idempotency_key' => 'k1'])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->postJson("/api/v1/leave/adjustments/bulk/$key/approve")->assertForbidden();
        $this->signIn($checker)->postJson("/api/v1/leave/adjustments/bulk/$key/approve")->assertOk()->assertJsonPath('data.approved', 2);
        $this->assertSame(3, $this->ledgerCount());
    }
}
