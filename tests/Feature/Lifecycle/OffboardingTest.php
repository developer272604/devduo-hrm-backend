<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\Contracts\{ClearanceProvider, ClearanceRegistry, SettlementInitiator};
use App\Domain\Lifecycle\LifecycleJobs;
use App\Domain\Lifecycle\Models\{Alumnus, ExitInterview, LifecycleCase, LifecycleTask};
use App\Domain\People\Lifecycle\SeparationService;
use App\Domain\People\Models\{Employee, SeparationRecord};
use App\Models\{User, WorkItem};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class OffboardingTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycleSetup();
        $this->publishedTemplate('offboarding', [
            ['title' => 'Return laptop', 'group_name' => 'IT', 'owner_type' => 'it', 'owner_user_id' => $this->checker->id, 'clearance_dept' => 'it', 'due_offset_days' => -1],
            ['title' => 'Expense settlement check', 'group_name' => 'Finance', 'owner_type' => 'finance', 'owner_user_id' => $this->checker->id, 'clearance_dept' => 'finance', 'due_offset_days' => 0],
            ['title' => 'Complete exit interview', 'group_name' => 'HR', 'owner_type' => 'employee', 'mandatory' => false, 'due_offset_days' => -2],
        ]);
        $this->app->instance(SettlementInitiator::class, new class implements SettlementInitiator {
            public array $calls = [];

            public function initiate(User $actor, Employee $employee, string $lastWorkingDate, array $data): string
            {
                $this->calls[] = [$employee->uuid, $lastWorkingDate];

                return 'settlement-'.count($this->calls);
            }
        });
    }

    private function leaver(): array
    {
        $this->context($this->admin->tenant_id);
        $emp = $this->hire('Lena Leaver', ['role' => 'Team member', 'email' => 'lena@alpha.test']);
        $this->context($this->admin->tenant_id);
        app(SeparationService::class)->initiate($this->admin, $emp, ['type' => 'resignation', 'last_working_date' => '2026-10-20']);
        $this->context($this->admin->tenant_id);
        $case = LifecycleCase::where('employee_id', $emp->id)->where('kind', 'offboarding')->firstOrFail();

        return [$emp, $case];
    }

    public function test_separation_starts_offboarding_and_clearance_blocks_settlement_until_cleared(): void
    {
        [$emp, $case] = $this->leaver();
        $this->assertSame('2026-10-20', $case->anchor_date->toDateString());
        $this->assertSame('separation', $case->source);
        $this->assertSame(3, LifecycleTask::where('case_id', $case->id)->count());
        $this->assertSame('2026-10-19', LifecycleTask::where('title', 'Return laptop')->first()->due_date->toDateString());

        $this->getJson('/api/v1/lifecycle/cases/'.$case->uuid.'/clearance')->assertOk()->assertJsonPath('cleared', false)->assertJsonCount(2, 'blockers');
        $this->postJson('/api/v1/lifecycle/cases/'.$case->uuid.'/settlement', ['currency' => 'inr'])->assertConflict()->assertJsonPath('code', 'clearance_pending')->assertJsonCount(2, 'blockers');

        $this->context($this->admin->tenant_id);
        $tasks = LifecycleTask::where('case_id', $case->id)->get()->keyBy('title');
        $this->signIn($this->checker);
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Return laptop']->uuid.'/complete')->assertOk();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/lifecycle/cases/'.$case->uuid.'/settlement', ['currency' => 'inr'])->assertConflict()->assertJsonCount(1, 'blockers');

        // A department module (e.g. Assets in a later phase) can also block through the clearance contract.
        app(ClearanceRegistry::class)->register(new class implements ClearanceProvider {
            public function key(): string { return 'assets'; }

            public function blockers(int $employeeId): array { return ['2 assets not returned']; }
        });
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Expense settlement check']->uuid.'/complete')->assertOk();
        $this->postJson('/api/v1/lifecycle/cases/'.$case->uuid.'/settlement', ['currency' => 'inr'])->assertConflict()->assertJsonPath('blockers.0.department', 'assets');

        app(ClearanceRegistry::class)->register(new \App\Domain\Lifecycle\Contracts\NullClearanceProvider('assets'));
        $res = $this->postJson('/api/v1/lifecycle/cases/'.$case->uuid.'/settlement', ['currency' => 'inr'])->assertOk()->assertJsonPath('settlement_uuid', 'settlement-1');
        $this->postJson('/api/v1/lifecycle/cases/'.$case->uuid.'/settlement', ['currency' => 'inr'])->assertOk()->assertJsonPath('settlement_uuid', $res->json('settlement_uuid')); // idempotent
        $this->assertCount(1, app(SettlementInitiator::class)->calls);
        $this->getJson('/api/v1/lifecycle/cases/'.$case->uuid)->assertJsonPath('status', 'completed');
    }

    public function test_exit_interview_is_confidential_encrypted_and_purged_after_retention(): void
    {
        [$emp, $case] = $this->leaver();
        $this->signIn(User::where('email', 'lena@alpha.test')->first());
        $this->getJson('/api/v1/lifecycle/me/tasks')->assertOk()->assertJsonPath('0.title', 'Complete exit interview');
        $this->putJson('/api/v1/lifecycle/me/exit-interview', ['answers' => ['reason' => 'Relocating', 'feedback' => 'Great team']])->assertOk()->assertJsonPath('status', 'submitted');
        $this->putJson('/api/v1/lifecycle/me/exit-interview', ['answers' => ['reason' => 'again']])->assertConflict();
        $this->getJson('/api/v1/lifecycle/cases/'.$case->uuid.'/exit-interview')->assertForbidden(); // employees cannot read the HR view
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, WorkItem::where('source_module', 'lifecycle')->where('title', 'Complete exit interview')->where('status', 'open')->count());

        $raw = \DB::table('lifecycle_exit_interviews')->where('case_id', $case->id)->value('answers');
        $this->assertStringNotContainsString('Relocating', $raw); // encrypted at rest

        $this->signIn($this->admin);
        $this->getJson('/api/v1/lifecycle/cases/'.$case->uuid.'/exit-interview')->assertOk()->assertJsonPath('answers.reason', 'Relocating');
        $this->assertDatabaseHas('audit_logs', ['action' => 'exit_interview.read', 'resource' => $case->uuid]);
        $this->getJson('/api/v1/lifecycle/cases/'.$case->uuid)->assertOk()->assertJsonMissingPath('answers'); // case detail never carries answers

        $this->context($this->admin->tenant_id);
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $this->signIn($hr)->getJson('/api/v1/lifecycle/cases/'.$case->uuid.'/exit-interview')->assertOk();

        $this->travelTo(now('UTC')->setDate(2030, 1, 1));
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, app(LifecycleJobs::class)->housekeeping()['exit_data_purged']);
        $this->assertNull(ExitInterview::where('case_id', $case->id)->value('answers'));
        $this->assertSame('purged', ExitInterview::where('case_id', $case->id)->value('status'));
    }

    public function test_withdrawn_separation_cancels_the_case_and_completion_creates_alumni_with_rehire_eligibility(): void
    {
        [$emp, $case] = $this->leaver();
        app(SeparationService::class)->withdraw($this->admin, $emp, 'Stayed');
        $this->context($this->admin->tenant_id);
        $this->assertSame('cancelled', $case->refresh()->status);
        $this->assertSame(0, WorkItem::where('source_module', 'lifecycle')->where('status', 'open')->count());

        app(SeparationService::class)->initiate($this->admin, Employee::find($emp->id), ['type' => 'resignation', 'last_working_date' => '2026-10-07']);
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, LifecycleCase::where('employee_id', $emp->id)->count()); // new case, anchor differs
        app(SeparationService::class)->complete($this->admin, Employee::find($emp->id));
        $this->context($this->admin->tenant_id);
        $this->assertSame('separated', Employee::find($emp->id)->status);
        $this->assertFalse((bool) User::where('email', 'lena@alpha.test')->value('active')); // access revoked through People's separation listener
        $alum = Alumnus::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('resignation', $alum->separation_type);

        $this->putJson('/api/v1/lifecycle/alumni/'.$alum->uuid.'/eligibility', ['rehire_eligible' => true, 'note' => 'Good leaver'])->assertOk()->assertJsonPath('rehire_eligible', true);
        $this->context($this->admin->tenant_id);
        $this->assertTrue((bool) SeparationRecord::where('employee_id', $emp->id)->where('status', 'completed')->value('rehire_eligible'));
        $this->getJson('/api/v1/lifecycle/alumni')->assertOk()->assertJsonPath('data.0.rehire_eligible', true);
    }

    public function test_escalation_and_reminders_are_idempotent(): void
    {
        [$emp, $case] = $this->leaver();
        $jobs = app(LifecycleJobs::class);

        $this->travelTo(now('UTC')->setDate(2026, 10, 18)->setTime(5, 0)); // laptop due 19th, interview due 18th
        $this->context($this->admin->tenant_id);
        $first = $jobs->remind();
        $this->assertGreaterThan(0, $first['reminded']);
        $this->assertSame(['reminded' => 0, 'escalated' => 0], $jobs->remind()); // second run is a no-op

        $this->travelTo(now('UTC')->setDate(2026, 10, 22)->setTime(5, 0)); // everything overdue
        $this->context($this->admin->tenant_id);
        $before = \DB::table('in_app_notifications')->count();
        $run = $jobs->remind();
        $this->assertSame(3, $run['escalated']);
        $after = \DB::table('in_app_notifications')->count();
        $this->assertGreaterThan($before, $after);
        $this->assertSame(['reminded' => 0, 'escalated' => 0], $jobs->remind());
        $this->assertSame($after, \DB::table('in_app_notifications')->count());
        $this->assertSame(3, LifecycleTask::whereNotNull('escalated_at')->count());
        $this->assertSame('urgent', WorkItem::where('source_module', 'lifecycle')->where('title', 'Return laptop')->value('priority'));
    }
}
