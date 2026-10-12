<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\Models\LifecycleCase;
use App\Domain\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class ContractRenewalTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    public function test_independent_approval_requires_template_and_extends_contract(): void
    {
        $this->lifecycleSetup();
        $employee = $this->hire('Contract Worker');
        $employee->forceFill(['contract_start' => '2026-01-01', 'contract_end' => '2026-12-31'])->save();
        $data = ['employee_uuid' => $employee->uuid, 'new_contract_end' => '2027-12-31', 'note' => 'Renew for another year'];

        $uuid = $this->postJson('/api/v1/lifecycle/contract-renewals', $data)->assertCreated()->assertJsonPath('status', 'pending')->json('uuid');
        $this->postJson('/api/v1/lifecycle/contract-renewals', $data)->assertConflict();
        $path = '/api/v1/lifecycle/contract-renewals/'.$uuid.'/decision';
        $decision = ['decision' => 'approve', 'note' => 'Approved', 'confirmed' => true];
        $this->postJson($path, $decision)->assertForbidden();

        $this->signIn($this->checker);
        $this->postJson($path, $decision)->assertUnprocessable();
        $this->publishedTemplate('contract_renewal', [['title' => 'Issue renewal letter', 'owner_type' => 'hr', 'mandatory' => true]]);
        $this->postJson($path, $decision)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('new_contract_end', '2027-12-31');
        $this->postJson($path, $decision)->assertConflict();

        $this->context($this->admin->tenant_id);
        $this->assertSame('2027-12-31', Employee::find($employee->id)->contract_end->toDateString());
        $this->assertSame(1, LifecycleCase::where('employee_id', $employee->id)->where('kind', 'contract_renewal')->count());
        $this->assertSame(0, \App\Models\WorkItem::where('source_entity', 'contract_renewal:'.$uuid)->where('status', 'open')->count());
    }

    public function test_stale_contract_date_blocks_approval_but_allows_rejection(): void
    {
        $this->lifecycleSetup();
        $employee = $this->hire('Stale Contract');
        $employee->forceFill(['contract_start' => '2026-01-01', 'contract_end' => '2026-12-31'])->save();
        $uuid = $this->postJson('/api/v1/lifecycle/contract-renewals', ['employee_uuid' => $employee->uuid, 'new_contract_end' => '2027-12-31', 'note' => 'Renew'])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        Employee::whereKey($employee->id)->update(['contract_end' => '2027-03-31']);

        $this->signIn($this->checker);
        $path = '/api/v1/lifecycle/contract-renewals/'.$uuid.'/decision';
        $this->publishedTemplate('contract_renewal', [['title' => 'Issue renewal letter', 'owner_type' => 'hr', 'mandatory' => true]]);
        $this->postJson($path, ['decision' => 'approve', 'note' => 'Stale', 'confirmed' => true])->assertConflict();
        $this->postJson($path, ['decision' => 'reject', 'note' => 'Create a fresh request', 'confirmed' => true])->assertOk()->assertJsonPath('status', 'rejected');
    }
}
