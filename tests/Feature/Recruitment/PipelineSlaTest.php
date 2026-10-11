<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\HiringService;
use App\Domain\Recruitment\RecruitmentHousekeeping;
use App\Models\InAppNotification;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PipelineSlaTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecruitment();
    }

    private function reminders(int $userId): int
    {
        $this->context($this->admin->tenant_id);

        return InAppNotification::where('user_id', $userId)->where('title', 'Hiring stage needs attention')->count();
    }

    private function housekeeping(): array
    {
        $this->context($this->admin->tenant_id);

        return app(RecruitmentHousekeeping::class)->run();
    }

    public function test_default_pipeline_equals_former_fixed_stages_and_keeps_strict_sequence(): void
    {
        $list = $this->getJson('/api/v1/recruitment/pipelines')->assertOk();
        $default = collect($list->json('data'))->firstWhere('code', 'DEFAULT');
        $this->assertTrue($default['is_default']);
        $this->assertSame(HiringService::STAGES, array_column($default['stages'], 'code'));
        $this->assertSame(['active', 'active', 'active', 'active', 'offer'], array_column($default['stages'], 'kind'));
        $this->assertSame([null], array_unique(array_column($default['stages'], 'sla_hours')));
        $this->assertFalse($default['allow_skip']);

        $app = $this->apply($this->requisition());
        $this->move($app, 'applied', 'interview')->assertUnprocessable();
        $this->move($app, 'applied', 'screening')->assertOk();
        $this->move($app, 'screening', 'applied')->assertUnprocessable();
        $this->move($app, 'screening', 'assessment', 'rejected')->assertUnprocessable(); // closing preserves the final stage
        $this->move($app, 'screening', 'screening', 'rejected')->assertOk();
        $this->getJson('/api/v1/recruitment/applications/'.$app.'/history')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_custom_pipeline_validates_definition_and_moves(): void
    {
        $bad = $this->stages();
        unset($bad[3]); // no offer stage
        $this->postJson('/api/v1/recruitment/pipelines', ['code' => 'BAD', 'name' => 'x', 'stages' => array_values($bad)])->assertUnprocessable();
        $order = $this->stages();
        [$order[3], $order[4]] = [$order[4], $order[3]]; // hired before offer: terminal must be last
        $this->postJson('/api/v1/recruitment/pipelines', ['code' => 'BAD', 'name' => 'x', 'stages' => $order])->assertUnprocessable();
        $dup = $this->stages();
        $dup[1]['code'] = 'new';
        $this->postJson('/api/v1/recruitment/pipelines', ['code' => 'BAD', 'name' => 'x', 'stages' => $dup])->assertUnprocessable();

        $this->pipeline('FAST');
        $this->postJson('/api/v1/recruitment/pipelines', ['code' => 'FAST', 'name' => 'x', 'stages' => $this->stages()])->assertConflict();
        $req = $this->requisition(1, ['pipeline_code' => 'FAST']);
        $app = $this->apply($req);
        $this->assertSame('new', collect($this->getJson('/api/v1/recruitment/applications')->json('data'))->firstWhere('uuid', $app)['stage']);
        $this->move($app, 'new', 'final')->assertUnprocessable(); // skipping not allowed
        $this->move($app, 'new', 'applied')->assertUnprocessable(); // not in this pipeline
        $this->move($app, 'new', 'review')->assertOk();
        $this->move($app, 'review', 'final')->assertOk();
        $this->move($app, 'final', 'hired')->assertUnprocessable(); // hired is never reached by a plain move
        $this->move($app, 'final', 'offer')->assertOk();
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->assertCreated();

        // Offers need a stage of kind "offer" in the requisition pipeline.
        $other = $this->apply($req, $this->candidate('other@example.test'));
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $other, 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->assertConflict();
    }

    public function test_allow_skip_permits_forward_jumps_only(): void
    {
        $this->pipeline('SKIP', ['allow_skip' => true]);
        $app = $this->apply($this->requisition(1, ['pipeline_code' => 'SKIP']));
        $this->move($app, 'new', 'final')->assertOk();
        $this->move($app, 'final', 'review')->assertUnprocessable();
        $this->move($app, 'final', 'hired')->assertUnprocessable();
    }

    public function test_pipeline_versions_are_immutable_once_used_and_existing_requisitions_keep_theirs(): void
    {
        $fast = $this->pipeline('FAST');
        $this->putJson('/api/v1/recruitment/pipelines/'.$fast, ['name' => 'Edited unused', 'stages' => $this->stages()])->assertOk()->assertJsonPath('name', 'Edited unused');
        $req1 = $this->requisition(1, ['pipeline_code' => 'FAST']);
        $this->context($this->admin->tenant_id);
        $this->assertSame((int) DB::table('recruitment_pipelines')->where('uuid', $fast)->value('id'), (int) DB::table('recruitment_requisitions')->where('uuid', $req1)->value('pipeline_id'));
        $this->putJson('/api/v1/recruitment/pipelines/'.$fast, ['name' => 'Edited used', 'stages' => $this->stages()])->assertConflict();
        $this->deleteJson('/api/v1/recruitment/pipelines/'.$fast)->assertConflict();

        $changed = $this->stages();
        $changed[1]['sla_hours'] = 12;
        $v2 = $this->postJson('/api/v1/recruitment/pipelines/'.$fast.'/versions', ['name' => 'Fast v2', 'stages' => $changed])->assertCreated()->assertJsonPath('version', 2)->json('uuid');
        $this->getJson('/api/v1/recruitment/pipelines/'.$fast)->assertJsonPath('status', 'retired')->assertJsonPath('stages.1.sla_hours', 48);
        $this->getJson('/api/v1/recruitment/pipelines/'.$fast.'/versions')->assertOk()->assertJsonCount(2);
        $req2 = $this->requisition(1, ['pipeline_code' => 'FAST']);
        $this->context($this->admin->tenant_id);
        $this->assertSame((int) DB::table('recruitment_pipelines')->where('uuid', $v2)->value('id'), (int) DB::table('recruitment_requisitions')->where('uuid', $req2)->value('pipeline_id'));
        $this->assertNotSame(DB::table('recruitment_requisitions')->where('uuid', $req1)->value('pipeline_id'), DB::table('recruitment_requisitions')->where('uuid', $req2)->value('pipeline_id'));
        $this->deleteJson('/api/v1/recruitment/pipelines/'.$v2)->assertConflict();
        $this->postJson('/api/v1/recruitment/pipelines/'.$fast.'/versions', ['name' => 'Stale base', 'stages' => $changed])->assertConflict(); // only the active version is the base

        // Unused version can be removed.
        $v3 = $this->postJson('/api/v1/recruitment/pipelines/'.$v2.'/versions', ['name' => 'Fast v3', 'stages' => $changed])->assertCreated()->json('uuid');
        $this->deleteJson('/api/v1/recruitment/pipelines/'.$v3)->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'pipeline.versioned']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pipeline.deleted', 'resource' => $v3]);
    }

    public function test_new_default_version_does_not_change_open_requisitions(): void
    {
        $req = $this->requisition();
        $default = collect($this->getJson('/api/v1/recruitment/pipelines')->json('data'))->firstWhere('code', 'DEFAULT');
        $changed = $default['stages'];
        $changed[0]['sla_hours'] = 5;
        $this->postJson('/api/v1/recruitment/pipelines/'.$default['uuid'].'/versions', ['name' => 'Default v2', 'stages' => $changed])->assertCreated()->assertJsonPath('is_default', true);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, (int) DB::table('recruitment_pipelines')->where('id', DB::table('recruitment_requisitions')->where('uuid', $req)->value('pipeline_id'))->value('version'));
        $newer = $this->requisition();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, (int) DB::table('recruitment_pipelines')->where('id', DB::table('recruitment_requisitions')->where('uuid', $newer)->value('pipeline_id'))->value('version'));
    }

    public function test_stage_reminders_follow_per_stage_sla_once_per_stage_and_recipients(): void
    {
        $this->pipeline('FAST');
        $app = $this->apply($this->requisition(1, ['pipeline_code' => 'FAST']));
        $t0 = now()->copy();
        $this->move($app, 'new', 'review')->assertOk();
        $this->travelTo($t0->copy()->addHours(47));
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);
        $this->travelTo($t0->copy()->addHours(49));
        $this->assertSame(1, $this->housekeeping()['stage_reminders']);
        $this->assertSame(1, $this->reminders($this->admin->id)); // recruiter
        $this->assertSame(1, $this->reminders($this->checker->id)); // hiring manager
        $this->assertSame(0, $this->housekeeping()['stage_reminders']); // idempotent for application + stage
        $this->travelTo($t0->copy()->addHours(60));
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);

        // A new stage restarts the clock with that stage's own threshold (24h, recruiter only).
        $this->signIn($this->admin);
        $this->move($app, 'review', 'final')->assertOk();
        $this->travelTo($t0->copy()->addHours(60 + 23));
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);
        $this->travelTo($t0->copy()->addHours(60 + 25));
        $this->assertSame(1, $this->housekeeping()['stage_reminders']);
        $this->assertSame(2, $this->reminders($this->admin->id));
        $this->assertSame(1, $this->reminders($this->checker->id));
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);
    }

    public function test_default_pipeline_falls_back_to_thirty_days_and_suppression_is_kept(): void
    {
        $req = $this->requisition();
        $app = $this->apply($req);
        $t0 = now()->copy();
        $this->travelTo($t0->copy()->addDays(29));
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);
        $this->travelTo($t0->copy()->addDays(31));
        $this->context($this->admin->tenant_id);
        DB::table('recruitment_requisitions')->where('uuid', $req)->update(['status' => 'closed']);
        $this->assertSame(0, $this->housekeeping()['stage_reminders']); // closed requisition suppressed
        DB::table('recruitment_requisitions')->where('uuid', $req)->update(['status' => 'open']);
        DB::table('recruitment_applications')->where('uuid', $app)->update(['status' => 'withdrawn']);
        $this->assertSame(0, $this->housekeeping()['stage_reminders']); // closed application suppressed
        DB::table('recruitment_applications')->where('uuid', $app)->update(['status' => 'active']);
        $this->assertSame(1, $this->housekeeping()['stage_reminders']);
        $this->assertSame(0, $this->housekeeping()['stage_reminders']);
    }

    public function test_feedback_reminder_window_is_configurable_on_the_pipeline(): void
    {
        $this->pipeline('FAST', ['feedback_reminder_hours' => 72]);
        $app = $this->apply($this->requisition(1, ['pipeline_code' => 'FAST']));
        $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00', 'timezone' => 'UTC', 'panelist_ids' => [$this->checker->id]])->assertCreated();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-09T17:00:00Z')); // 30h after the end: the old fixed 24h would have fired
        $this->assertSame(0, $this->housekeeping()['feedback_reminders']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-11T12:00:00Z'));
        $this->assertSame(1, $this->housekeeping()['feedback_reminders']);
        $this->assertSame(0, $this->housekeeping()['feedback_reminders']);
    }

    public function test_analytics_report_sla_breaches_and_average_time_in_stage(): void
    {
        $this->pipeline('FAST');
        $req = $this->requisition(2, ['pipeline_code' => 'FAST']);
        $one = $this->apply($req);
        $two = $this->apply($req, $this->candidate('second@example.test'));
        $t0 = now()->copy();
        $this->travelTo($t0->copy()->addHours(5));
        $this->move($two, 'new', 'review')->assertOk();
        $this->travelTo($t0->copy()->addHours(10));
        $this->move($one, 'new', 'review')->assertOk();
        $this->move($one, 'review', 'final')->assertOk();
        $this->travelTo($t0->copy()->addHours(60));
        $sla = $this->getJson('/api/v1/recruitment/analytics')->assertOk()->json('sla');
        $this->assertSame(['active' => 1, 'breached' => 1], $sla['by_stage']['review']);
        $this->assertSame(['active' => 1, 'breached' => 1], $sla['by_stage']['final']);
        $this->assertSame(2, $sla['breached_total']);
        $this->assertSame('7.5', $sla['average_hours_in_stage']['new']);
        $this->assertSame('0.0', $sla['average_hours_in_stage']['review']);
    }

    public function test_authorization_and_tenant_isolation(): void
    {
        $fast = $this->pipeline('FAST');
        $this->context($this->admin->tenant_id);
        $viewer = Role::create(['name' => 'Hiring viewer', 'data_scope' => 'tenant', 'system' => false]);
        $viewer->permissions()->sync(Permission::whereIn('key', ['recruitment.view', 'recruitment.manage'])->pluck('id'));
        $this->checker->roles()->sync([$viewer->id]);
        $this->signIn($this->checker);
        $this->getJson('/api/v1/recruitment/pipelines')->assertOk();
        $this->postJson('/api/v1/recruitment/pipelines', ['code' => 'NOPE', 'name' => 'x', 'stages' => $this->stages()])->assertForbidden();
        $this->putJson('/api/v1/recruitment/pipelines/'.$fast, ['name' => 'x', 'stages' => $this->stages()])->assertForbidden();
        $this->deleteJson('/api/v1/recruitment/pipelines/'.$fast)->assertForbidden();

        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member)->getJson('/api/v1/recruitment/pipelines')->assertForbidden();

        $other = $this->bootTenant('beta');
        $this->signIn($other);
        $this->getJson('/api/v1/recruitment/pipelines/'.$fast)->assertNotFound();
        $this->putJson('/api/v1/recruitment/pipelines/'.$fast, ['name' => 'x', 'stages' => $this->stages()])->assertNotFound();
        $this->postJson('/api/v1/recruitment/pipelines/'.$fast.'/versions', ['name' => 'x', 'stages' => $this->stages()])->assertNotFound();
        $codes = collect($this->getJson('/api/v1/recruitment/pipelines')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['DEFAULT'], $codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pipeline.created', 'resource' => $fast]);
    }
}
