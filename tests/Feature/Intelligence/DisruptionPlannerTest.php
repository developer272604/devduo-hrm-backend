<?php

namespace Tests\Feature\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class DisruptionPlannerTest extends PerformanceTestCase
{
    private const D = '/api/v1/intelligence/disruption';

    private int $python;

    private int $sql;

    private int $leaveType;

    protected function setUp(): void
    {
        parent::setUp(); // 2026-10-07; mgr + emp1..3 in Engineering, outsider too
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $this->python = $this->skill('Python');
        $this->sql = $this->skill('SQL');
        $this->leaveType = DB::table('leave_types')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'name' => 'Medical', 'code' => 'MED', 'category' => 'paid', 'unit' => 'day', 'tracks_balance' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function skill(string $name): int
    {
        return DB::table('skills')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function know($employee, int $skill, int $rank): void
    {
        DB::table('employee_skills')->insert(['tenant_id' => $this->admin->tenant_id, 'person_id' => $employee->person_id, 'skill_id' => $skill, 'proficiency' => 'intermediate', 'level_rank' => $rank,
            'verification_status' => 'unverified', 'source' => 'self', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function leave($employee, string $from, string $to, string $status = 'approved', $delegate = null): void
    {
        DB::table('leave_requests')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $employee->id, 'leave_type_id' => $this->leaveType, 'status' => $status,
            'start_date' => $from, 'end_date' => $to, 'reason' => 'Surgery recovery', 'delegate_employee_id' => $delegate?->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plan(string $from = '2026-10-12', string $to = '2026-10-16', int $pct = 80)
    {
        return $this->getJson(self::D."?from=$from&to=$to&min_available_pct=$pct");
    }

    public function test_shortage_days_are_found_from_approved_leave_only(): void
    {
        $this->leave($this->emp1, '2026-10-12', '2026-10-16');
        $this->leave($this->emp2, '2026-10-14', '2026-10-15');
        $this->leave($this->emp3, '2026-10-12', '2026-10-16', 'pending'); // not approved: not counted
        $r = $this->signIn($this->admin)->plan()->assertOk();
        $dept = $r->json('departments.0');
        $this->assertSame(5, $dept['headcount']);
        $this->assertSame(['2026-10-14', '2026-10-15'], array_column($dept['shortage_days'], 'date')); // 3 of 5 = 60%; 4 of 5 = 80% is not below 80
        $this->assertEquals(60.0, $dept['worst_day']['available_pct']);
        $this->assertSame(2, $dept['worst_day']['away']);
        $this->assertSame([], array_column($this->plan('2026-10-12', '2026-10-16', 60)->json('departments.0.shortage_days'), 'date')); // 60% is not below 60
    }

    public function test_cover_comes_from_the_delegate_then_trained_backups_then_skill_matched_colleagues(): void
    {
        $this->know($this->emp1, $this->python, 3);
        $this->know($this->emp1, $this->sql, 2);
        $this->know($this->emp3, $this->python, 3);
        $this->know($this->emp3, $this->sql, 2); // full fit
        $this->know($this->mgr, $this->python, 2); // partial fit
        $this->know($this->emp2, $this->python, 4); // would fit, but is away at the same time
        $this->leave($this->emp1, '2026-10-12', '2026-10-16', 'approved', $this->emp3);
        $this->leave($this->emp2, '2026-10-14', '2026-10-15');
        DB::table('continuity_responsibilities')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'owner_employee_id' => $this->emp1->id, 'title' => 'Month-end cut-off', 'criticality' => 'critical',
            'backup_employee_id' => $this->emp3->id, 'backup_trained' => true, 'documented' => true, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('continuity_responsibilities')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'owner_employee_id' => $this->emp1->id, 'title' => 'Vendor calls', 'criticality' => 'high',
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('talent_profiles')->insert(['tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp3->id, 'discoverable' => true, 'spare_hours_per_week' => 12, 'created_at' => now(), 'updated_at' => now()]);

        $r = $this->signIn($this->admin)->plan()->assertOk();
        $a = collect($r->json('absences'))->first(fn ($x) => $x['employee']['name'] === 'Arun Kumar');
        $this->assertSame('leave', $a['kind']);
        $this->assertSame('Chen Wei', $a['delegate']['name']);
        $this->assertSame('Month-end cut-off', $a['continuity_backups'][0]['responsibility']);
        $this->assertSame('Chen Wei', $a['continuity_backups'][0]['backup']['name']);
        $this->assertSame(['Vendor calls'], array_column($a['uncovered_responsibilities'], 'responsibility'));
        $names = array_column(array_column($a['candidates'], 'employee'), 'name');
        $this->assertSame(['Chen Wei', 'Meera Rao'], $names); // Bela is away; the outsider has no matching skills
        $this->assertEquals(1.0, $a['candidates'][0]['skill_fit']);
        $this->assertEquals(0.33, $a['candidates'][1]['skill_fit']); // Python 2 vs 3 = 0.67, SQL 0 -> (0.67 + 0) / 2
        $this->assertSame(12, $a['candidates'][0]['free_hours']);
        $this->assertNull($a['candidates'][1]['free_hours']); // never declared
        $this->assertSame(['Python', 'SQL'], array_column($a['key_skills'], 'skill'));
    }

    public function test_known_exits_count_from_the_day_after_the_last_working_day(): void
    {
        DB::table('lifecycle_resignations')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $this->emp3->id, 'requested_by' => $this->admin->id,
            'requested_last_day' => '2026-10-13', 'reason' => 'x', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employees')->where('id', $this->emp2->id)->update(['status' => 'notice_period', 'separated_on' => '2026-10-14']);
        $r = $this->signIn($this->admin)->plan()->assertOk();
        $exits = collect($r->json('absences'))->where('kind', 'exit')->keyBy('employee.name');
        $this->assertSame('2026-10-14', $exits['Chen Wei']['from']);
        $this->assertSame('2026-10-15', $exits['Bela Shah']['from']);
        $this->assertSame('2026-10-16', $exits['Bela Shah']['to']);
        $day = collect($r->json('departments.0.shortage_days'))->keyBy('date');
        $this->assertEquals(60.0, $r->json('departments.0.worst_day.available_pct')); // from the 15th two of five are gone
        $this->assertSame(['2026-10-15', '2026-10-16'], array_column($r->json('departments.0.shortage_days'), 'date')); // the 14th is 80%, not below 80
        $this->assertArrayNotHasKey('2026-10-13', $day->all());
    }

    public function test_a_manager_sees_only_their_scope_and_no_leave_type_or_reason_is_exposed(): void
    {
        $this->leave($this->outsider, '2026-10-12', '2026-10-16');
        $this->leave($this->emp1, '2026-10-12', '2026-10-16');
        $r = $this->actAs($this->mgr)->plan()->assertOk();
        $this->assertSame(4, $r->json('people_in_scope')); // the manager and three reports, not the outsider
        $this->assertSame(['Arun Kumar'], array_column(array_column($r->json('absences'), 'employee'), 'name'));
        $json = json_encode($r->json());
        foreach (['Surgery', 'Medical', '"reason"', 'leave_type'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_validation_and_permissions(): void
    {
        $this->signIn($this->admin);
        $this->plan('2026-10-12', '2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->plan('2026-10-01', '2026-12-31')->assertStatus(422)->assertJsonValidationErrors('to'); // more than 62 days
        $this->plan('2026-02-30', '2026-03-05')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson(self::D.'?from=2026-10-12&to=2026-10-16&min_available_pct=0')->assertStatus(422);
        $this->actAs($this->emp1)->plan()->assertForbidden();
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->assertSame(0, $this->plan()->assertOk()->json('people_in_scope'));
    }
}
