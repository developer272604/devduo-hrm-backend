<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class CommunicationPreferencesTest extends PerformanceTestCase
{
    public function test_employee_can_manage_channels_and_preferences_are_tenant_scoped(): void
    {
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/communication-preferences')->assertOk()
            ->assertJsonPath('channels.announcements.in_app', true)->assertJsonPath('channels.announcements.email', false);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => [
            'announcements' => ['in_app' => false, 'email' => true], 'surveys' => ['in_app' => false],
        ]])->assertOk()->assertJsonPath('channels.announcements.in_app', false)->assertJsonPath('channels.announcements.email', true)
            ->assertJsonPath('channels.surveys.in_app', false)->assertJsonPath('channels.recognition.in_app', true);
        $this->signIn($this->admin);
        $this->assertNull(app(\App\Domain\Notifications\NotificationService::class)->send($this->userOf($this->emp1)->id,
            'Optional update', 'Read the employee update.', 'experience.announcement', '/experience/announcements'));
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $this->userOf($this->emp1)->id, 'title' => 'Optional update']);

        $alphaUserId = $this->userOf($this->emp1)->id;
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/communication-preferences')->assertOk()->assertJsonPath('channels.announcements.in_app', true);
        $this->assertDatabaseHas('experience_notification_preferences', ['tenant_id' => $this->emp1->tenant_id, 'user_id' => $alphaUserId, 'announcements_in_app' => false]);
    }

    public function test_due_announcements_follow_optional_preferences_but_required_notices_bypass_opt_out(): void
    {
        $employeeUserId = $this->userOf($this->emp1)->id;
        $this->actAs($this->emp1);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => ['announcements' => ['in_app' => false]]])->assertOk();
        $this->signIn($this->admin);
        $optional = $this->postJson('/api/v1/experience/announcements', ['title' => 'Optional update', 'body' => 'General update.'])->assertCreated()->json();
        $required = $this->postJson('/api/v1/experience/announcements', ['title' => 'Required policy notice', 'body' => 'Please acknowledge.', 'acknowledgment_required' => true])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$optional['uuid'].'/publish')->assertOk();
        $this->postJson('/api/v1/experience/announcements/'.$required['uuid'].'/publish')->assertOk();

        $this->artisan('experience:deliver-announcements', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $optionalId = \DB::table('experience_announcements')->where('uuid', $optional['uuid'])->value('id');
        $optionalDelivery = \DB::table('experience_announcement_deliveries')->where('announcement_id', $optionalId)->where('user_id', $employeeUserId)->first();
        $this->assertNotNull($optionalDelivery?->suppressed_at);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $employeeUserId, 'title' => 'Employee announcement']);
        $notificationCount = \DB::table('in_app_notifications')->where('user_id', $employeeUserId)->where('title', 'Employee announcement')->count();
        $this->artisan('experience:deliver-announcements', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $this->assertSame($notificationCount, \DB::table('in_app_notifications')->where('user_id', $employeeUserId)->where('title', 'Employee announcement')->count());

        $requiredId = \DB::table('experience_announcements')->where('uuid', $required['uuid'])->value('id');
        $requiredDelivery = \DB::table('experience_announcement_deliveries')->where('announcement_id', $requiredId)->where('user_id', $employeeUserId)->first();
        $this->assertNotNull($requiredDelivery?->sent_at);
        $this->assertNull($requiredDelivery?->suppressed_at);
    }

    public function test_targeted_announcement_notifications_follow_the_audience_snapshot(): void
    {
        $london = $this->hire('Lina London', ['location' => 1, 'role' => 'Team member', 'email' => 'lina.london@alpha.test']);
        $londonUserId = $this->userOf($london)->id;
        $announcement = $this->postJson('/api/v1/experience/announcements', ['title' => 'London site update', 'body' => 'Local update.',
            'audience' => ['location_ids' => [$this->org['location'][1]]]])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$announcement['uuid'].'/publish')->assertOk();
        $this->artisan('experience:deliver-announcements', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $londonUserId, 'title' => 'Employee announcement']);
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $this->userOf($this->emp1)->id, 'title' => 'Employee announcement']);
    }

    public function test_event_campaign_delivery_respects_the_events_preference(): void
    {
        $employeeUserId = $this->userOf($this->emp1)->id;
        $this->actAs($this->emp1);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => ['events' => ['in_app' => false]]])->assertOk();
        $this->signIn($this->admin);
        $event = $this->postJson('/api/v1/experience/announcements', ['title' => 'Optional event', 'body' => 'Join us.', 'communication_type' => 'event',
            'event_starts_at' => now()->addDays(5)->format('Y-m-d\\TH:i:s')])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$event['uuid'].'/publish')->assertOk();
        $this->artisan('experience:deliver-announcements', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $id = \DB::table('experience_announcements')->where('uuid', $event['uuid'])->value('id');
        $delivery = \DB::table('experience_announcement_deliveries')->where('announcement_id', $id)->where('user_id', $employeeUserId)->first();
        $this->assertNotNull($delivery?->suppressed_at);
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $employeeUserId, 'title' => 'Employee event']);
    }
}
