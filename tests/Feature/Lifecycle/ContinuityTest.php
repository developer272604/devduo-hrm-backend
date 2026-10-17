<?php

namespace Tests\Feature\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class ContinuityTest extends PerformanceTestCase
{
    private const C = '/api/v1/lifecycle/continuity/';

    private function add(array $over = [])
    {
        return $this->postJson(self::C.'responsibilities', $over + ['owner_uuid' => $this->emp1->uuid, 'title' => 'Month-end payroll cut-off', 'criticality' => 'critical']);
    }

    private function resign($employee, string $inDays, string $status = 'pending'): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('lifecycle_resignations')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'requested_by' => $this->admin->id,
            'requested_last_day' => now()->addDays((int) $inDays)->toDateString(), 'reason' => 'Moving on', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_validation_rules_for_documentation_and_backup(): void
    {
        $this->actAs($this->mgr);
        $this->add(['documented' => true])->assertStatus(422)->assertJsonValidationErrors('documentation_ref');
        $this->add(['backup_trained' => true])->assertStatus(422)->assertJsonValidationErrors('backup_uuid');
        $this->add(['backup_uuid' => $this->emp1->uuid])->assertStatus(422)->assertJsonValidationErrors('backup_uuid'); // owner cannot be their own backup
        $this->add(['criticality' => 'extreme'])->assertStatus(422);
        $this->add(['owner_uuid' => $this->outsider->uuid])->assertNotFound(); // outside the manager's reach
    }

    public function test_gaps_follow_backup_training_and_documentation(): void
    {
        $this->actAs($this->mgr);
        $r = $this->add()->assertCreated()->json();
        $this->assertSame(['no_backup', 'undocumented'], $r['gaps']);
        $this->assertFalse($r['covered']);

        $r = $this->patchJson(self::C."responsibilities/{$r['uuid']}", ['backup_uuid' => $this->emp2->uuid])->assertOk()->json();
        $this->assertSame(['backup_not_trained', 'undocumented'], $r['gaps']);
        $this->assertSame('Bela Shah', $r['backup']['name']);

        $r = $this->patchJson(self::C."responsibilities/{$r['uuid']}", ['backup_trained' => true, 'documented' => true, 'documentation_ref' => 'wiki/payroll-cutoff'])->assertOk()->json();
        $this->assertSame([], $r['gaps']);
        $this->assertTrue($r['covered']);

        // Removing the backup resets training.
        $r = $this->patchJson(self::C."responsibilities/{$r['uuid']}", ['backup_uuid' => null])->assertOk()->json();
        $this->assertFalse($r['backup_trained']);
        $this->assertContains('no_backup', $r['gaps']);
    }

    public function test_risk_levels_use_exit_dates_from_resignations_and_notice_periods(): void
    {
        $this->actAs($this->mgr);
        $this->add()->assertCreated(); // emp1: critical, uncovered
        $this->add(['owner_uuid' => $this->emp2->uuid, 'title' => 'Vendor onboarding', 'criticality' => 'high'])->assertCreated(); // emp2: high, uncovered
        $this->add(['owner_uuid' => $this->emp3->uuid, 'title' => 'Team rota', 'criticality' => 'low'])->assertCreated(); // emp3: low only
        $this->resign($this->emp1, '30');
        $this->resign($this->emp2, '90', 'approved');
        $r = $this->getJson(self::C.'risk')->assertOk();
        $by = collect($r->json('owners'))->keyBy('owner.name');
        $this->assertSame('urgent', $by['Arun Kumar']['level']);
        $this->assertSame(30, $by['Arun Kumar']['exit']['days_left']);
        $this->assertSame('resignation pending', $by['Arun Kumar']['exit']['source']);
        $this->assertSame('plan', $by['Bela Shah']['level']); // exit known but not within 60 days
        $this->assertSame('low', $by['Chen Wei']['level']); // low criticality, no exit
        $this->assertSame(['Arun Kumar', 'Bela Shah', 'Chen Wei'], array_column(array_column($r->json('owners'), 'owner'), 'name')); // most urgent first
        $this->assertSame(1, $r->json('summary.urgent'));

        // A notice-period last working day counts too, and the earlier date wins.
        DB::table('employees')->where('id', $this->emp3->id)->update(['status' => 'notice_period', 'separated_on' => now()->addDays(10)->toDateString()]);
        $this->add(['owner_uuid' => $this->emp3->uuid, 'title' => 'Release approvals', 'criticality' => 'critical'])->assertCreated();
        $by = collect($this->getJson(self::C.'risk')->json('owners'))->keyBy('owner.name');
        $this->assertSame('urgent', $by['Chen Wei']['level']);
        $this->assertSame('notice period', $by['Chen Wei']['exit']['source']);
    }

    public function test_a_covered_owner_and_a_withdrawn_resignation_do_not_raise_risk(): void
    {
        $this->actAs($this->mgr);
        $uuid = $this->add()->json('uuid');
        $this->resign($this->emp1, '20');
        $this->resign($this->emp1, '20', 'withdrawn');
        $this->patchJson(self::C."responsibilities/$uuid", ['backup_uuid' => $this->emp2->uuid, 'backup_trained' => true, 'documented' => true, 'documentation_ref' => 'wiki/x'])->assertOk();
        $this->assertSame([], $this->getJson(self::C.'risk')->json('owners'));
        $this->patchJson(self::C."responsibilities/$uuid", ['documented' => false])->assertOk();
        $this->assertSame('urgent', $this->getJson(self::C.'risk')->json('owners.0.level')); // the pending resignation still counts
    }

    public function test_handover_opens_each_follow_up_task_once_and_retiring_closes_them(): void
    {
        $this->actAs($this->mgr);
        $uuid = $this->add()->json('uuid');
        $this->patchJson(self::C."responsibilities/$uuid", ['backup_uuid' => $this->emp2->uuid])->assertOk();
        $this->add(['title' => 'Client escalations'])->assertCreated(); // no backup at all
        $first = $this->postJson(self::C.'handover/'.$this->emp1->uuid)->assertOk();
        $this->assertSame(4, $first->json('created')); // document x2, shadow x1, name a backup x1
        $this->assertSame(0, $this->postJson(self::C.'handover/'.$this->emp1->uuid)->json('created'));
        $this->assertSame(4, $this->postJson(self::C.'handover/'.$this->emp1->uuid)->json('already_open'));
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'continuity')->where('assignee_id', $this->userOf($this->emp2)->id)->where('title', 'like', 'Shadow%')->count());

        $this->patchJson(self::C."responsibilities/$uuid", ['status' => 'retired'])->assertOk();
        $this->assertSame(0, DB::table('work_items')->where('source_entity', 'like', 'responsibility:'.$uuid.'%')->where('status', 'open')->count());
    }

    public function test_visibility_follows_data_scope_and_permissions(): void
    {
        $this->actAs($this->mgr);
        $this->add()->assertCreated();
        $this->signIn($this->admin);
        $this->add(['owner_uuid' => $this->outsider->uuid, 'title' => 'Treasury runs'])->assertCreated();
        $this->assertCount(2, $this->getJson(self::C.'responsibilities')->assertOk()->json('items'));
        $this->actAs($this->mgr);
        $items = $this->getJson(self::C.'responsibilities')->assertOk()->json('items');
        $this->assertSame(['Month-end payroll cut-off'], array_column($items, 'title')); // the outsider's entry is outside the manager's reach
        $this->assertCount(1, $this->getJson(self::C.'risk')->json('owners'));

        $this->actAs($this->emp1);
        $this->getJson(self::C.'responsibilities')->assertForbidden();
        $this->postJson(self::C.'handover/'.$this->emp1->uuid)->assertForbidden();

        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->assertSame([], $this->getJson(self::C.'responsibilities')->assertOk()->json('items'));
    }
}
