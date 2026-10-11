<?php

namespace Tests\Feature\Leave;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PolicyTypesTest extends PolicyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    private function type(array $o = []): array
    {
        return $this->postJson('/api/v1/leave/types', $o + ['name' => 'Annual leave', 'code' => 'al', 'kind' => 'annual'])->assertCreated()->json('data');
    }

    public function test_type_crud_validation_and_normalisation(): void
    {
        $t = $this->type(['config' => ['countries' => ['in'], 'evidence' => ['required' => true, 'after_units' => 3]]]);
        $this->assertSame('AL', $t['code']);
        $this->assertSame(['IN'], $t['config']['countries']);
        $this->assertTrue($t['tracks_balance']);
        $this->postJson('/api/v1/leave/types', ['name' => 'Dup', 'code' => 'AL'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/leave/types', ['name' => 'Bad', 'code' => 'BAD', 'kind' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/leave/types', ['name' => 'Bad', 'code' => 'BAD2', 'config' => ['law' => 'x']])->assertUnprocessable();
        $med = $this->type(['name' => 'Medical', 'code' => 'MED', 'kind' => 'medical']);
        $this->assertTrue($med['is_sensitive'], 'medical types are sensitive by default');
        $stat = $this->type(['name' => 'Statutory', 'code' => 'STAT', 'category' => 'statutory']);
        $this->assertTrue($stat['is_statutory']);
        $this->patchJson('/api/v1/leave/types/'.$t['uuid'], ['name' => 'Annual', 'color' => '#336699'])->assertOk()->assertJsonPath('data.name', 'Annual');
        $this->getJson('/api/v1/leave/types')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/leave/types/meta')->assertOk()->assertJsonPath('data.units.0', 'day');
        $this->deleteJson('/api/v1/leave/types/'.$t['uuid'])->assertNoContent();
        $this->getJson('/api/v1/leave/types/'.$t['uuid'])->assertNotFound();
    }

    public function test_referenced_types_cannot_change_shape_or_be_deleted(): void
    {
        $t = $this->type();
        $e = $this->hire('Asha Rao');
        LeaveAccount::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::byUuid($t['uuid'])->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $this->patchJson('/api/v1/leave/types/'.$t['uuid'], ['unit' => 'hour'])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->patchJson('/api/v1/leave/types/'.$t['uuid'], ['code' => 'XX'])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/types/'.$t['uuid'], ['unit' => 'day', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->deleteJson('/api/v1/leave/types/'.$t['uuid'])->assertUnprocessable();
    }

    public function test_seed_defaults_is_idempotent_and_country_neutral(): void
    {
        $first = $this->postJson('/api/v1/leave/types/seed-defaults')->assertOk()->json('data');
        $this->assertCount(6, $first['created']);
        $second = $this->postJson('/api/v1/leave/types/seed-defaults')->assertOk()->json('data');
        $this->assertSame([], $second['created']);
        $this->assertSame(6, LeaveType::query()->count());
        $this->assertNull(LeaveType::query()->where('code', 'ANNUAL')->first()->config);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $t = $this->type();
        $emp = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
        $user = \App\Models\User::query()->where('email', 'asha@alpha.test')->first();
        $this->signIn($user);
        $this->getJson('/api/v1/leave/types')->assertOk();
        $this->postJson('/api/v1/leave/types', ['name' => 'X', 'code' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/leave/policies')->assertForbidden();
        $this->getJson('/api/v1/leave/cycles')->assertForbidden();
        $this->deleteJson('/api/v1/leave/types/'.$t['uuid'])->assertForbidden();

        $other = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($other);
        $this->getJson('/api/v1/leave/types/'.$t['uuid'])->assertNotFound();
        $this->getJson('/api/v1/leave/types')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/leave/types', ['name' => 'Annual leave', 'code' => 'AL'])->assertCreated(); // same code in another tenant is fine
        $this->assertNotNull($emp);
    }

    public function test_restriction_document_rules_and_optional_holiday_pools(): void
    {
        $t = $this->type();
        $this->postJson('/api/v1/leave/restriction-rules', ['name' => 'Year end freeze', 'kind' => 'blackout'])->assertUnprocessable();
        $r = $this->postJson('/api/v1/leave/restriction-rules', ['name' => 'Year end freeze', 'kind' => 'blackout', 'action' => 'block', 'start_date' => '2026-12-20', 'end_date' => '2026-12-31',
            'target_type' => 'department', 'target_id' => $this->org['department'][0], 'leave_type' => $t['uuid']])->assertCreated()->assertJsonPath('data.exempt_statutory', true)->json('data');
        $this->postJson('/api/v1/leave/restriction-rules', ['name' => 'Cap', 'kind' => 'max_concurrent'])->assertUnprocessable()->assertJsonValidationErrors('params.max');
        $this->postJson('/api/v1/leave/restriction-rules', ['name' => 'Cap', 'kind' => 'blackout', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'target_type' => 'department', 'target_id' => 99999])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/restriction-rules/'.$r['uuid'], ['action' => 'warn'])->assertOk()->assertJsonPath('data.action', 'warn')->assertJsonPath('data.leave_type', $t['uuid']);
        $this->getJson('/api/v1/leave/restriction-rules?kind=blackout')->assertOk()->assertJsonCount(1, 'data');

        $d = $this->postJson('/api/v1/leave/document-rules', ['leave_type' => $t['uuid'], 'required_after_units' => '2.5', 'document_kind' => 'medical_certificate', 'submit_within_days' => 7])
            ->assertCreated()->assertJsonPath('data.required_after_units', '2.5000')->json('data');
        $this->postJson('/api/v1/leave/document-rules', ['leave_type' => $t['uuid'], 'required_after_units' => '1.00001'])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/document-rules/'.$d['id'], ['mandatory' => false])->assertOk()->assertJsonPath('data.mandatory', false)->assertJsonPath('data.required_after_units', '2.5000');
        $this->deleteJson('/api/v1/leave/document-rules/'.$d['id'])->assertNoContent();

        $pool = $this->postJson('/api/v1/leave/optional-holiday-pools', ['name' => 'Floating', 'year' => 2026, 'max_selection' => 2, 'leave_type' => $t['uuid'],
            'holidays' => [['date' => '2026-03-04', 'name' => 'A'], ['date' => '2026-08-20', 'name' => 'B'], ['date' => '2026-11-01', 'name' => 'C']]])->assertCreated()->json('data');
        $this->assertSame(['2026-03-04', '2026-08-20', '2026-11-01'], array_column($pool['holidays'], 'date'));
        $this->postJson('/api/v1/leave/optional-holiday-pools', ['name' => 'Floating', 'year' => 2026, 'holidays' => [['date' => '2026-03-04']]])->assertUnprocessable();
        $this->postJson('/api/v1/leave/optional-holiday-pools', ['name' => 'Bad', 'year' => 2026, 'max_selection' => 5, 'holidays' => [['date' => '2026-03-04']]])->assertUnprocessable();
        $this->postJson('/api/v1/leave/optional-holiday-pools', ['name' => 'Bad', 'year' => 2026, 'holidays' => [['date' => '2025-03-04']]])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/optional-holiday-pools/'.$pool['uuid'], ['max_selection' => 1])->assertOk()->assertJsonPath('data.max_selection', 1);
        $this->getJson('/api/v1/leave/optional-holiday-pools?year=2026')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/leave/optional-holiday-pools/'.$pool['uuid'])->assertNoContent();
    }
}
