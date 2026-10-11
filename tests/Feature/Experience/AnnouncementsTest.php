<?php

namespace Tests\Feature\Experience;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Performance\PerformanceTestCase;

class AnnouncementsTest extends PerformanceTestCase
{
    public function test_published_announcement_tracks_employee_read_and_acknowledgment(): void
    {
        $created = $this->postJson('/api/v1/experience/announcements', [
            'title' => 'Benefits enrollment closes Friday', 'body' => 'Please review the available plans.', 'acknowledgment_required' => true,
        ])->assertCreated()->json();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/publish')->assertOk()->assertJsonPath('status', 'published');

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.acknowledged_at', null);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/read')->assertOk()->assertJsonPath('read_at', fn ($value) => ! empty($value))->assertJsonPath('acknowledged_at', null);
        $response = $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertOk();
        $response->assertJsonPath('read_at', fn ($value) => ! empty($value))->assertJsonPath('acknowledged_at', fn ($value) => ! empty($value));
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertOk();

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertNotFound();
    }

    public function test_acknowledgment_is_rejected_when_not_required(): void
    {
        $created = $this->postJson('/api/v1/experience/announcements', [
            'title' => 'Office hours', 'body' => 'The office will close early.',
        ])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertUnprocessable();
    }

    public function test_announcement_audience_expiry_and_small_audience_metrics_are_enforced(): void
    {
        $london = $this->hire('Lina London', ['location' => 1, 'role' => 'Team member']);
        $announcement = $this->postJson('/api/v1/experience/announcements', ['title' => 'London office update', 'body' => 'A local update.',
            'pinned' => true, 'expires_at' => now()->format('Y-m-d'), 'audience' => ['location_ids' => [$this->org['location'][1]]]])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$announcement['uuid'].'/publish')->assertOk();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->actAs($london);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pinned', true);
        $this->travelTo(now()->addDays(1));
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(0, 'data');

        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/announcements/'.$announcement['uuid'].'/metrics')->assertOk()->assertJsonPath('suppressed', true)->assertJsonMissingPath('audience_count');
    }

    public function test_event_campaign_rsvp_is_private_replaceable_and_metrics_are_thresholded(): void
    {
        $starts = now()->addDays(3)->format('Y-m-d\\TH:i:s');
        $event = $this->postJson('/api/v1/experience/announcements', ['title' => 'Workspace gathering', 'body' => 'Join us for the team event.',
            'communication_type' => 'event', 'event_starts_at' => $starts, 'event_ends_at' => now()->addDays(3)->addHour()->format('Y-m-d\\TH:i:s'), 'event_location' => 'Main hall'])
            ->assertCreated()->assertJsonPath('communication_type', 'event')->json();
        $this->postJson('/api/v1/experience/announcements/'.$event['uuid'].'/publish')->assertOk();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonPath('data.0.event_location', 'Main hall')->assertJsonPath('data.0.my_rsvp', null);
        $this->putJson('/api/v1/experience/announcements/'.$event['uuid'].'/rsvp', ['response' => 'attending'])->assertOk()->assertJsonPath('response', 'attending');
        $this->putJson('/api/v1/experience/announcements/'.$event['uuid'].'/rsvp', ['response' => 'maybe'])->assertOk()->assertJsonPath('response', 'maybe');
        $this->assertSame(1, \DB::table('experience_event_rsvps')->where('announcement_id', \DB::table('experience_announcements')->where('uuid', $event['uuid'])->value('id'))->where('user_id', $this->userOf($this->emp1)->id)->count());
        $this->getJson('/api/v1/experience/announcements')->assertJsonPath('data.0.my_rsvp', 'maybe');

        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/announcements/'.$event['uuid'].'/metrics')->assertOk()
            ->assertJsonPath('rsvps.maybe', null)->assertJsonPath('rsvps.suppressed.maybe', true);
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->putJson('/api/v1/experience/announcements/'.$event['uuid'].'/rsvp', ['response' => 'attending'])->assertNotFound();
    }

    public function test_onboarding_communication_targets_only_the_new_hire_snapshot(): void
    {
        $newHire = $this->hire('Nora Onboard', ['role' => 'Team member', 'joined_on' => now()->toDateString()]);
        $message = $this->postJson('/api/v1/experience/announcements', ['title' => 'Getting started', 'body' => 'Welcome to the team.',
            'communication_type' => 'onboarding', 'audience' => ['new_hires_within_days' => 7]])->assertCreated()->assertJsonPath('communication_type', 'onboarding')->json();
        $this->postJson('/api/v1/experience/announcements/'.$message['uuid'].'/publish')->assertOk();

        $this->actAs($newHire);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Getting started');
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_announcement_attachments_are_private_and_follow_announcement_audience(): void
    {
        Storage::fake('experience_private');
        $london = $this->hire('Lina London', ['location' => 1, 'role' => 'Team member']);
        $announcement = $this->postJson('/api/v1/experience/announcements', [
            'title' => 'Employee guide', 'body' => 'Read the attached guide.',
            'audience' => ['location_ids' => [$this->org['location'][1]]],
        ])->assertCreated()->json();
        $upload = $this->post('/api/v1/experience/announcements/'.$announcement['uuid'].'/attachments', ['file' => UploadedFile::fake()->create('guide.pdf', 120, 'application/pdf')]);
        $upload->assertCreated()->assertJsonPath('filename', 'guide.pdf');
        $attachment = $upload->json('uuid');
        $this->actAs($this->emp1);
        $this->get('/api/v1/experience/announcements/'.$announcement['uuid'].'/attachments/'.$attachment)->assertNotFound();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/announcements/'.$announcement['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->get('/api/v1/experience/announcements/'.$announcement['uuid'].'/attachments/'.$attachment)->assertNotFound();
        $this->actAs($london);
        $this->get('/api/v1/experience/announcements/'.$announcement['uuid'].'/attachments/'.$attachment)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, \DB::table('audit_logs')->where('action', 'announcement.attachment_accessed')->count());
    }
}
