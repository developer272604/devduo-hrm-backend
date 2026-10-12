<?php
namespace Tests\Feature\Lifecycle;
use App\Domain\People\Services\StatusService;
use App\Domain\Lifecycle\Models\LifecycleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;
class ReturnTest extends TestCase {
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;
    public function test_independent_approval_requires_template_and_returns_employee(): void {
        $this->lifecycleSetup();
        $e = $this->hire('Returning Employee');
        $this->context($this->admin->tenant_id);
        app(StatusService::class)->transition($e, 'long_leave');
        $data = ['employee_uuid' => $e->uuid, 'note' => 'Ready to resume duties'];
        $uuid = $this->postJson('/api/v1/lifecycle/returns', $data)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/lifecycle/returns', $data)->assertConflict();
        $path = '/api/v1/lifecycle/returns/'.$uuid.'/decision';
        $decision = ['decision' => 'approve', 'note' => 'Return confirmed', 'confirmed' => true];
        $this->postJson($path, $decision)->assertForbidden();
        $this->signIn($this->checker);
        $this->postJson($path, $decision)->assertUnprocessable();
        $this->publishedTemplate('absence_return', [['title' => 'Restore access', 'owner_type' => 'hr', 'mandatory' => true]]);
        $this->postJson($path, $decision)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($path, $decision)->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame(1, LifecycleCase::where('employee_id', $e->id)->where('kind', 'absence_return')->count());
        $this->assertLessThanOrEqual(40, strlen(LifecycleCase::where('employee_id', $e->id)->where('kind', 'absence_return')->value('source')));
        $this->assertSame(0, \App\Models\WorkItem::where('source_entity', 'return:'.$uuid)->where('status', 'open')->count());
    }
    public function test_intervening_status_change_blocks_stale_approval_but_allows_rejection(): void {
        $this->lifecycleSetup();
        $e = $this->hire('Stale Return');
        $this->context($this->admin->tenant_id);
        $status = app(StatusService::class);
        $status->transition($e, 'long_leave');
        $uuid = $this->postJson('/api/v1/lifecycle/returns', ['employee_uuid' => $e->uuid, 'note' => 'Return'])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $status->transition($e, 'active');
        $status->transition($e, 'long_leave');
        $this->signIn($this->checker);
        $path = '/api/v1/lifecycle/returns/'.$uuid.'/decision';
        $this->postJson($path, ['decision' => 'approve', 'note' => 'Stale', 'confirmed' => true])->assertConflict();
        $this->postJson($path, ['decision' => 'reject', 'note' => 'New absence needs a new request', 'confirmed' => true])->assertOk()->assertJsonPath('status', 'rejected');
    }
}
