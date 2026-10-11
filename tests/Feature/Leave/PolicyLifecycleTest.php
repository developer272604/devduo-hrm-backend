<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveCycle;
use App\Domain\Leave\Models\LeavePolicyTypeRule;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PolicyLifecycleTest extends PolicyTestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private LeaveType $sick;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant(); // today = 2026-10-07
        $this->signIn($this->admin);
        $this->annual = LeaveType::create(['name' => 'Annual', 'code' => 'AL', 'kind' => 'annual']);
        $this->sick = LeaveType::create(['name' => 'Sick', 'code' => 'SL', 'kind' => 'sick']);
    }

    private function rules(string $amount = '12', array $extra = []): array
    {
        return ['entitlement' => ['basis' => 'annual', 'amount' => $amount], 'carry_forward' => ['mode' => 'capped', 'cap' => '5'], 'limits' => ['max_units' => '10']] + $extra;
    }

    private function policy(array $o = []): array
    {
        return $this->postJson('/api/v1/leave/policies', $o + ['name' => 'Standard', 'code' => 'STD', 'effective_from' => '2026-01-01', 'rules' => ['notice_days' => 1],
            'type_rules' => [['type' => 'AL', 'rules' => $this->rules()]]])->assertCreated()->json('data');
    }

    private function publish(string $uuid, int $v, int $status = 200)
    {
        return $this->postJson("/api/v1/leave/policies/$uuid/versions/$v/publish")->assertStatus($status);
    }

    public function test_create_validates_rules_and_never_floats_units(): void
    {
        $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'rules' => ['made_up' => 1]])->assertUnprocessable()->assertJsonValidationErrors('rules.made_up');
        $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'type_rules' => [['type' => 'AL', 'rules' => ['entitlement' => ['amount' => '1.23456']]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('type_rules.AL.entitlement.amount');
        $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'type_rules' => [['type' => 'AL', 'rules' => ['carry_forward' => ['mode' => 'capped']]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('type_rules.AL.carry_forward.cap');
        $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'type_rules' => [['type' => 'AL', 'rules' => ['limits' => ['min_units' => '3', 'max_units' => '2']]]]])->assertUnprocessable();
        $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'type_rules' => [['type' => 'NOPE', 'rules' => []]]])->assertUnprocessable();
        $p = $this->policy();
        $this->postJson('/api/v1/leave/policies', ['name' => 'Again', 'code' => 'STD'])->assertUnprocessable();
        $this->assertSame('draft', $p['versions'][0]['status']);
        $this->assertSame(1, LeavePolicyTypeRule::query()->count());
    }

    public function test_publish_activates_freezes_and_checksums_the_version(): void
    {
        $p = $this->policy();
        $res = $this->publish($p['uuid'], 1)->json('data');
        $this->assertSame('active', $res['status']);
        $this->assertTrue($res['immutable']);
        $this->assertTrue($res['integrity_ok']);
        $this->assertSame(64, strlen($res['checksum']));
        $this->patchJson("/api/v1/leave/policies/{$p['uuid']}/versions/1", ['rules' => ['notice_days' => 5]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson("/api/v1/leave/policies/{$p['uuid']}/versions/1/type-rules/SL", ['rules' => $this->rules('6')])->assertUnprocessable();
        $this->deleteJson("/api/v1/leave/policies/{$p['uuid']}/versions/1/type-rules/AL")->assertUnprocessable();
        $this->deleteJson("/api/v1/leave/policies/{$p['uuid']}/versions/1")->assertUnprocessable();
        $this->publish($p['uuid'], 1, 422); // cannot publish twice
        // tampering with the stored rules is detectable
        LeavePolicyTypeRule::query()->update(['rules' => $this->rules('99')]);
        $this->getJson("/api/v1/leave/policies/{$p['uuid']}/versions/1")->assertOk()->assertJsonPath('data.integrity_ok', false);
    }

    public function test_publish_requires_a_leave_type_and_existing_cycle(): void
    {
        $p = $this->postJson('/api/v1/leave/policies', ['name' => 'Empty', 'code' => 'EMP', 'effective_from' => '2026-01-01'])->assertCreated()->json('data');
        $this->publish($p['uuid'], 1, 422);
        $this->putJson("/api/v1/leave/policies/{$p['uuid']}/versions/1/type-rules/AL", ['rules' => $this->rules('12', ['cycle' => 'GHOST'])])->assertOk();
        $this->publish($p['uuid'], 1, 422);
        LeaveCycle::create(['name' => 'Cal', 'code' => 'GHOST']);
        $this->publish($p['uuid'], 1);
    }

    public function test_new_version_clones_and_publishing_closes_previous_without_rewriting_it(): void
    {
        $p = $this->policy();
        $this->publish($p['uuid'], 1);
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('change_note');
        $v2 = $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2026-12-01', 'change_note' => 'Raise entitlement'])->assertCreated()->json('data');
        $this->assertSame(2, $v2['version']);
        $this->assertSame('12', $v2['type_rules'][0]['rules']['entitlement']['amount'], 'type rules are cloned from the base');
        $this->putJson("/api/v1/leave/policies/{$p['uuid']}/versions/2/type-rules/AL", ['rules' => $this->rules('15')])->assertOk();
        $before = LeavePolicyVersion::query()->where('version', 1)->first();
        $checksum = $before->checksum;
        $rulesBefore = $before->rules;

        $res = $this->publish($p['uuid'], 2)->json();
        $this->assertSame('scheduled', $res['data']['status']);
        $this->assertSame('2026-11-30', $res['closed'][0]['effective_to']);
        $v1 = LeavePolicyVersion::query()->where('version', 1)->first();
        $this->assertSame('2026-11-30', $v1->effective_to);
        $this->assertSame('active', $v1->status);
        $this->assertSame($checksum, $v1->checksum);
        $this->assertSame($rulesBefore, $v1->rules);
        $this->assertTrue(app(\App\Domain\Leave\Policies\PolicyService::class)->integrityOk($v1), 'closing a range does not change what was approved');

        // a duplicate start date cannot be published
        $v3 = $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2026-12-01', 'change_note' => 'Clash'])->assertCreated()->json('data');
        $this->publish($p['uuid'], $v3['version'], 422);
    }

    public function test_schedule_requires_a_future_date_and_retire_cancels_a_scheduled_version(): void
    {
        $p = $this->policy();
        $this->publish($p['uuid'], 1);
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['change_note' => 'Later'])->assertCreated();
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/2/schedule", ['effective_from' => '2026-10-07'])->assertUnprocessable();
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/2/schedule", ['effective_from' => '2027-01-01'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame('2026-12-31', LeavePolicyVersion::query()->where('version', 1)->value('effective_to'));

        $r = $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/2/retire")->assertOk()->json();
        $this->assertSame('retired', $r['data']['status']);
        $this->assertStringContainsString('Version 1 is in effect again', $r['warnings'][0]);
        $this->assertNull(LeavePolicyVersion::query()->where('version', 1)->value('effective_to'));
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/2/retire")->assertUnprocessable();

        $ret = $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/1/retire")->assertOk()->json();
        $this->assertSame('2026-10-07', $ret['data']['effective_to']);
        $this->assertNotEmpty($ret['warnings']);
    }

    public function test_status_follows_dates_and_never_alters_the_rules(): void
    {
        $p = $this->policy(['effective_from' => '2026-01-01']);
        $this->publish($p['uuid'], 1);
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2026-10-20', 'change_note' => 'Next'])->assertCreated();
        $this->publish($p['uuid'], 2);
        $this->assertSame('scheduled', LeavePolicyVersion::query()->where('version', 2)->value('status'));
        $this->travelTo(now('UTC')->setDate(2026, 10, 25));
        $list = $this->getJson('/api/v1/leave/policies')->assertOk()->json('data.0.versions');
        $this->assertSame(['retired', 'active'], array_column($list, 'status'));
        $this->assertTrue(app(\App\Domain\Leave\Policies\PolicyService::class)->integrityOk(LeavePolicyVersion::query()->where('version', 1)->first()));
    }

    public function test_back_dated_publish_is_refused_when_it_would_rewrite_leave_history(): void
    {
        $p = $this->policy(['effective_from' => '2026-01-01']);
        $this->publish($p['uuid'], 1);
        $e = $this->hire('Asha Rao');
        LeaveAccount::create(['employee_id' => $e->id, 'leave_type_id' => $this->annual->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'policy_version_id' => LeavePolicyVersion::query()->where('version', 1)->value('id')]);
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2026-06-01', 'change_note' => 'Backdated'])->assertCreated();
        $this->publish($p['uuid'], 2, 422)->assertJsonValidationErrors('effective_from');
        $this->assertSame('draft', LeavePolicyVersion::query()->where('version', 2)->value('status'));
    }

    public function test_diff_viewer_reports_leaf_changes_per_section(): void
    {
        $p = $this->policy();
        $this->publish($p['uuid'], 1);
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['effective_from' => '2027-01-01', 'change_note' => 'Tweaks', 'rules' => ['notice_days' => 3, 'backdate_days' => 7],
            'type_rules' => [['type' => 'AL', 'rules' => array_replace($this->rules('12.0000', ['pay_class' => 'paid']), ['limits' => ['max_units' => '8']])], ['type' => 'SL', 'rules' => $this->rules('6')]]])->assertCreated();
        $d = $this->getJson("/api/v1/leave/policies/{$p['uuid']}/diff?from=1&to=2")->assertOk()->json('data');
        $changes = collect($d['changes'])->keyBy(fn ($c) => $c['section'].':'.$c['path']);
        $this->assertSame('changed', $changes['policy:notice_days']['change']);
        $this->assertSame('added', $changes['policy:backdate_days']['change']);
        $this->assertSame('added', $changes['AL:pay_class']['change']);
        $this->assertSame('changed', $changes['AL:limits.max_units']['change']);
        $this->assertSame('type_added', $changes['SL:*']['change']);
        $this->assertArrayNotHasKey('AL:entitlement.amount', $changes->all(), '12 and 12.0000 are the same number');
        $this->assertFalse($d['identical']);
        $this->assertSame(2, $d['to']['version']);
        $same = $this->getJson("/api/v1/leave/policies/{$p['uuid']}/diff?from=1&to=1")->assertOk()->json('data');
        $this->assertTrue($same['identical']);
        $this->getJson("/api/v1/leave/policies/{$p['uuid']}/diff?from=1&to=9")->assertNotFound();
    }

    public function test_delete_draft_only_and_permissions(): void
    {
        $p = $this->policy();
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions", ['change_note' => 'Scratch'])->assertCreated();
        $this->deleteJson("/api/v1/leave/policies/{$p['uuid']}/versions/2")->assertNoContent();
        $this->assertSame(1, LeavePolicyVersion::query()->count());
        $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $this->signIn(\App\Models\User::query()->where('email', 'maya@alpha.test')->first());
        $this->getJson('/api/v1/leave/policies')->assertForbidden();
        $this->postJson("/api/v1/leave/policies/{$p['uuid']}/versions/1/publish")->assertForbidden();
        $this->getJson("/api/v1/leave/policies/{$p['uuid']}/diff?from=1&to=1")->assertForbidden();
    }
}
