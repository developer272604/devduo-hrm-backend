<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Support\LeaveSettings;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SettingsTest extends PolicyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    public function test_settings_are_readable_and_partially_updated_with_defaults_and_other_tenant_settings_preserved(): void
    {
        $tenant = Tenant::findOrFail($this->currentTenant);
        $tenant->settings = ['modules' => ['leave', 'people'], 'people' => ['probation_days' => 90], 'leave' => ['requests' => ['backdate_days' => 14]]];
        $tenant->save();

        $this->getJson('/api/v1/leave/settings')
            ->assertOk()
            ->assertJsonPath('data.requests.backdate_days', 14)
            ->assertJsonPath('data.requests.pending_expiry_days', 90)
            ->assertJsonPath('data.balance.carry_negative_forward', true)
            ->assertJsonPath('data.requests.future_days', 365)
            ->assertJsonPath('data.units.minutes_per_day', 480);

        $this->putJson('/api/v1/leave/settings', ['requests' => ['require_reason' => true], 'units' => ['rounding' => 'ceil']])
            ->assertOk()
            ->assertJsonPath('data.requests.require_reason', true)
            ->assertJsonPath('data.requests.backdate_days', 14)
            ->assertJsonPath('data.units.rounding', 'ceil');

        $stored = Tenant::findOrFail($this->currentTenant)->settings;
        $this->assertSame(['leave', 'people'], $stored['modules']);
        $this->assertSame(['probation_days' => 90], $stored['people']);
        $this->assertSame(14, $stored['leave']['requests']['backdate_days']);

        $this->putJson('/api/v1/leave/settings', ['requests' => ['pending_expiry_days' => null]])
            ->assertOk()
            ->assertJsonPath('data.requests.pending_expiry_days', null);
    }

    public function test_settings_reject_unknown_keys_and_invalid_policy_values(): void
    {
        $this->putJson('/api/v1/leave/settings', ['requests' => ['max_days' => 5]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings');
        $this->putJson('/api/v1/leave/settings', ['units' => ['rounding' => 'random']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('units.rounding');
        $this->putJson('/api/v1/leave/settings', ['approvals' => ['steps' => ['ceo']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approvals.steps.0');
        $this->assertSame(480, LeaveSettings::get('units.minutes_per_day'));
    }

    public function test_leave_settings_require_management_permission(): void
    {
        $member = $this->userWithRole('Team member', 'settings-member@alpha.test');
        $this->signIn($member);

        $this->getJson('/api/v1/leave/settings')->assertForbidden();
        $this->putJson('/api/v1/leave/settings', ['requests' => ['require_reason' => true]])->assertForbidden();
    }
}
