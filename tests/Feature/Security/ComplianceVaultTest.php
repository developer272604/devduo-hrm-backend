<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Performance\PerformanceTestCase;

class ComplianceVaultTest extends PerformanceTestCase
{
    private const C = '/api/v1/security/compliance/';

    private function control(array $over = []): array
    {
        $this->signIn($this->admin);

        return $this->postJson(self::C.'controls', $over + ['code' => 'pf-return', 'name' => 'Monthly provident fund return', 'category' => 'statutory', 'frequency' => 'monthly',
            'owner_user_id' => $this->admin->id, 'next_due_on' => '2026-10-15'])->assertCreated()->json();
    }

    private function evidence(string $uuid, array $over = [])
    {
        return $this->postJson(self::C."controls/$uuid/evidence", $over + ['collected_on' => '2026-10-06', 'title' => 'September return acknowledgement', 'reference' => 'drive://compliance/pf/2026-09.pdf']);
    }

    public function test_controls_are_validated_and_start_with_no_statutory_dates(): void
    {
        $this->signIn($this->admin);
        $this->assertSame([], $this->getJson(self::C.'controls')->assertOk()->json('items')); // nothing is pre-filled
        $this->control();
        $base = ['code' => 'PF-RETURN', 'name' => 'x', 'category' => 'statutory', 'frequency' => 'monthly', 'owner_user_id' => $this->admin->id, 'next_due_on' => '2026-10-15'];
        $this->postJson(self::C.'controls', $base)->assertStatus(422)->assertJsonValidationErrors('code'); // codes are case-insensitive unique
        $this->postJson(self::C.'controls', ['code' => 'a1', 'next_due_on' => null] + $base)->assertStatus(422)->assertJsonValidationErrors('next_due_on');
        $this->postJson(self::C.'controls', ['code' => 'a2', 'owner_user_id' => 99999] + $base)->assertStatus(422)->assertJsonValidationErrors('owner_user_id');
        $this->postJson(self::C.'controls', ['code' => 'a3', 'frequency' => 'weekly'] + $base)->assertStatus(422);
        $this->postJson(self::C.'controls', ['code' => 'a4', 'frequency' => 'one_time', 'next_due_on' => null] + $base)->assertCreated(); // one-time needs no recurring date
    }

    public function test_status_follows_the_due_date(): void
    {
        $this->control(['code' => 'far', 'next_due_on' => '2026-11-30']);
        $this->control(['code' => 'soon', 'next_due_on' => '2026-10-15']);
        $this->control(['code' => 'late', 'next_due_on' => '2026-10-01']);
        $by = collect($this->getJson(self::C.'controls')->assertOk()->json('items'))->keyBy('code');
        $this->assertSame('current', $by['FAR']['status']);
        $this->assertSame('due_soon', $by['SOON']['status']);
        $this->assertSame('overdue', $by['LATE']['status']);
        $this->assertSame(-6, $by['LATE']['days_until_due']);
        $this->assertSame(1, $this->getJson(self::C.'controls')->json('summary.overdue'));
        $this->assertStringContainsString('own compliance advisor', $this->getJson(self::C.'controls')->json('note'));
    }

    public function test_evidence_counts_only_after_independent_acceptance_and_then_moves_the_due_date(): void
    {
        $c = $this->control();
        $e = $this->evidence($c['uuid'])->assertCreated()->assertJsonPath('status', 'submitted')->json();
        $this->assertSame('awaiting_review', $this->getJson(self::C."controls/{$c['uuid']}")->json('status'));
        $this->evidence($c['uuid'])->assertStatus(422); // one pending submission at a time
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => true])->assertForbidden(); // the submitter cannot review their own evidence

        $this->signIn($this->checker);
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => false, 'note' => 'Acknowledgement page missing'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('2026-10-15', $this->getJson(self::C."controls/{$c['uuid']}")->json('next_due_on')); // a rejection does not move the date

        $this->signIn($this->admin);
        $e2 = $this->evidence($c['uuid'], ['title' => 'Resubmitted with acknowledgement'])->assertCreated()->json();
        $this->signIn($this->checker);
        $this->postJson(self::C."evidence/{$e2['uuid']}/review", ['accept' => true])->assertOk()->assertJsonPath('status', 'accepted');
        $detail = $this->getJson(self::C."controls/{$c['uuid']}")->json();
        $this->assertSame('2026-11-15', $detail['next_due_on']); // one month on from the date the evidence covered
        $this->assertSame(1, $detail['accepted_count']);
        $this->assertSame('current', $detail['status']);
        $this->postJson(self::C."evidence/{$e2['uuid']}/review", ['accept' => true])->assertStatus(422); // already decided
        $this->assertSame(2, count($detail['evidence']));
    }

    public function test_a_one_time_control_completes_and_future_dated_evidence_is_refused(): void
    {
        $c = $this->control(['code' => 'audit', 'frequency' => 'one_time', 'next_due_on' => '2026-10-20']);
        $this->evidence($c['uuid'], ['collected_on' => '2026-10-30'])->assertStatus(422)->assertJsonValidationErrors('collected_on');
        $e = $this->evidence($c['uuid'])->json();
        $this->signIn($this->checker);
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => true])->assertOk();
        $d = $this->getJson(self::C."controls/{$c['uuid']}")->json();
        $this->assertNull($d['next_due_on']);
        $this->assertSame('complete', $d['status']);
    }

    public function test_the_owner_may_submit_but_other_viewers_may_not(): void
    {
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Compliance viewer', 'description' => 'x', 'data_scope' => 'self', 'system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', 'security.compliance.view')->value('id')]);
        $owner = $this->userOf($this->emp1);
        $other = $this->userOf($this->emp2);
        foreach ([$owner, $other] as $u) {
            DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $u->id]);
        }
        $c = $this->control(['owner_user_id' => $owner->id]);
        $this->signIn($other);
        $this->evidence($c['uuid'])->assertForbidden();
        $this->postJson(self::C.'controls', ['code' => 'x', 'name' => 'x', 'category' => 'policy', 'frequency' => 'annual', 'owner_user_id' => $other->id, 'next_due_on' => '2027-01-01'])->assertForbidden();
        $this->getJson(self::C.'review-queue')->assertForbidden();
        $this->signIn($owner);
        $this->evidence($c['uuid'])->assertCreated();
    }

    public function test_reminders_open_one_task_per_control_and_review_closes_it(): void
    {
        $due = $this->control(['code' => 'due', 'next_due_on' => '2026-10-10']);
        $this->control(['code' => 'later', 'next_due_on' => '2026-12-31']);
        $this->assertSame(1, $this->postJson(self::C.'remind')->assertOk()->json('created'));
        $this->assertSame(0, $this->postJson(self::C.'remind')->json('created'));
        $this->assertSame(1, $this->postJson(self::C.'remind')->json('already_open'));
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'compliance')->where('status', 'open')->count());
        $e = $this->evidence($due['uuid'])->json();
        $this->signIn($this->checker);
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => true])->assertOk();
        $this->assertSame(0, DB::table('work_items')->where('source_module', 'compliance')->where('status', 'open')->count());
    }

    public function test_the_auditor_pack_lists_accepted_evidence_with_a_stable_checksum_and_flags_gaps(): void
    {
        $a = $this->control();
        $this->control(['code' => 'training', 'name' => 'Annual POSH training', 'frequency' => 'annual', 'next_due_on' => '2026-12-01']);
        $e = $this->evidence($a['uuid'])->json();
        $this->signIn($this->checker);
        $this->postJson(self::C."evidence/{$e['uuid']}/review", ['accept' => true])->assertOk();

        $p1 = $this->getJson(self::C.'pack?from=2026-09-01&to=2026-10-07')->assertOk()->json();
        $p2 = $this->getJson(self::C.'pack?from=2026-09-01&to=2026-10-07')->json();
        $this->assertSame($p1['sha256'], $p2['sha256']); // same content, same checksum
        $this->assertSame(64, strlen($p1['sha256']));
        $this->assertSame(1, $p1['controls_without_evidence']);
        $pf = collect($p1['controls'])->firstWhere('code', 'PF-RETURN');
        $this->assertSame('September return acknowledgement', $pf['evidence'][0]['title']);
        $this->assertNotNull($pf['evidence'][0]['accepted_by']);
        $this->assertTrue(collect($p1['controls'])->firstWhere('code', 'TRAINING')['gap']);

        $empty = $this->getJson(self::C.'pack?from=2026-01-01&to=2026-01-31')->json();
        $this->assertNotSame($p1['sha256'], $empty['sha256']);
        $this->getJson(self::C.'pack?from=2026-10-07&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson(self::C.'pack?from=2026-02-30&to=2026-03-01')->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_tenant_isolation(): void
    {
        $c = $this->control();
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->assertSame([], $this->getJson(self::C.'controls')->assertOk()->json('items'));
        $this->getJson(self::C."controls/{$c['uuid']}")->assertNotFound();
    }
}
