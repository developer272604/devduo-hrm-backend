<?php

namespace Tests\Feature\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class DecisionQualityTest extends PerformanceTestCase
{
    private const URL = '/api/v1/intelligence/decisions';

    private int $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $this->leaveType = DB::table('leave_types')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'name' => 'Casual', 'code' => 'CL', 'category' => 'paid', 'unit' => 'day', 'tracks_balance' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function decide(int $by, int $hours, string $status = 'approved', string $decidedAt = '2026-09-15 10:00:00'): void
    {
        $submitted = date('Y-m-d H:i:s', strtotime($decidedAt) - $hours * 3600);
        DB::table('leave_requests')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $this->leaveType, 'status' => $status,
            'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'submitted_at' => $submitted, 'decided_at' => $decidedAt, 'decided_by' => $by, 'created_at' => $submitted, 'updated_at' => $decidedAt]);
    }

    private function report(string $q = 'from=2026-09-01&to=2026-10-07')
    {
        return $this->getJson(self::URL.'?'.$q);
    }

    private function seedDecisions(): void
    {
        $mgr = $this->userOf($this->mgr)->id;
        foreach ([10, 10, 10, 10, 10, 10, 60, 60, 60, 60] as $h) {
            $this->decide($mgr, $h);
        }
        $this->decide($mgr, 100, 'rejected');
        $this->decide($mgr, 100, 'rejected');
        foreach ([5, 5, 5] as $h) {
            $this->decide($this->checker->id, $h); // too few decisions to show
        }
        $this->decide($mgr, 5, 'pending'); // not a decision
        $this->decide($mgr, 5, 'approved', '2026-07-01 10:00:00'); // outside the period
    }

    public function test_turnaround_target_breaches_and_approval_share_are_computed_per_person(): void
    {
        $this->seedDecisions();
        $r = $this->signIn($this->admin)->report()->assertOk();
        $this->assertSame('company', $r->json('scope'));
        $row = collect($r->json('rows'))->firstWhere('person.name', 'Meera Rao');
        $this->assertSame(12, $row['decisions']);
        $this->assertEquals(35.0, $row['median_hours']); // sorted: six at 10h then four at 60h and two at 100h -> (10 + 60) / 2
        $this->assertEquals(50.0, $row['slower_than_target_pct']); // 6 of 12 took more than 48 hours
        $this->assertEquals(83.3, $row['approved_pct']);
        $this->assertSame(12, $row['by_source']['leave']['decisions']);
        $this->assertStringContainsString('not a performance rating', $r->json('limitations'));
        $r2 = $this->report('from=2026-09-01&to=2026-10-07&target_hours=72')->json();
        $this->assertEquals(16.7, collect($r2['rows'])->firstWhere('person.name', 'Meera Rao')['slower_than_target_pct']); // only the two 100h decisions exceed 72h
    }

    public function test_people_with_too_few_decisions_are_withheld_and_counted_not_named(): void
    {
        $this->seedDecisions();
        $r = $this->signIn($this->admin)->report()->assertOk();
        $this->assertSame(['Meera Rao'], array_column(array_column($r->json('rows'), 'person'), 'name'));
        $this->assertSame(1, $r->json('withheld_people')); // the administrator with three decisions
        $this->assertSame(10, $r->json('minimum_decisions'));
    }

    public function test_someone_without_company_wide_access_sees_only_their_own_numbers(): void
    {
        $this->seedDecisions();
        $r = $this->actAs($this->mgr)->report()->assertOk();
        $this->assertSame('own', $r->json('scope'));
        $this->assertCount(1, $r->json('rows'));
        $this->assertTrue($r->json('rows.0.person.is_you'));
        $this->assertNull($r->json('withheld_people')); // nothing is revealed about other people, not even how many
        $this->actAs($this->emp1)->report()->assertForbidden();
    }

    public function test_validation(): void
    {
        $this->signIn($this->admin);
        $this->report('from=2026-10-07&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->report('from=2026-01-01&to=2026-10-07')->assertStatus(422)->assertJsonValidationErrors('to'); // more than 180 days
        $this->report('from=2026-02-30&to=2026-03-05')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->report('from=2026-09-01&to=2026-10-07&target_hours=0')->assertStatus(422);
    }

    public function test_tenant_isolation(): void
    {
        $this->seedDecisions();
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->assertSame([], $this->report()->assertOk()->json('rows'));
    }
}
