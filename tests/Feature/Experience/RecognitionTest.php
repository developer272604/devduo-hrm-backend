<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class RecognitionTest extends PerformanceTestCase
{
    public function test_recognition_requires_moderation_and_opt_out_is_enforced(): void
    {
        $this->actAs($this->emp2);
        $this->putJson('/api/v1/experience/recognitions/preference', ['opted_out' => true])->assertOk()->assertJsonPath('opted_out', true);

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/recognitions/recipients?search=Bela')->assertOk()->assertJsonCount(0);
        $this->postJson('/api/v1/experience/recognitions', ['recipient_uuid' => $this->emp2->uuid, 'message' => 'Great collaboration.', 'value_tags' => ['teamwork']])->assertUnprocessable();

        $this->actAs($this->emp3);
        $created = $this->postJson('/api/v1/experience/recognitions', ['recipient_uuid' => $this->emp1->uuid, 'message' => 'Thanks for sharing your expertise.',
            'value_tags' => ['teamwork', 'ownership'], 'reward_reference' => 'badge-42'])->assertCreated()->assertJsonPath('status', 'pending')->json();
        $this->getJson('/api/v1/experience/recognitions')->assertOk()->assertJsonCount(0, 'data');

        $this->actAs($this->emp1);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => ['recognition' => ['in_app' => false]]])->assertOk();

        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/recognitions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $created['uuid']);
        $this->postJson('/api/v1/experience/recognitions/'.$created['uuid'].'/moderate', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $this->userOf($this->emp1)->id, 'title' => 'You received recognition']);
        $this->postJson('/api/v1/experience/recognitions/'.$created['uuid'].'/moderate', ['decision' => 'rejected'])->assertUnprocessable();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/recognitions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reward_reference', 'badge-42');
        $this->putJson('/api/v1/experience/recognitions/preference', ['opted_out' => true])->assertOk();
        $this->getJson('/api/v1/experience/recognitions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recognition_is_tenant_scoped_and_cannot_be_self_awarded(): void
    {
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/recognitions', ['recipient_uuid' => $this->emp1->uuid, 'message' => 'Self', 'value_tags' => ['ownership']])->assertUnprocessable();
        $this->postJson('/api/v1/experience/recognitions', ['recipient_uuid' => $this->emp2->uuid, 'message' => 'Great work.', 'value_tags' => ['ownership']])->assertCreated();
        $this->bootTenant('beta');
        $this->actAs($this->admin);
        $this->getJson('/api/v1/experience/recognitions')->assertOk()->assertJsonCount(0, 'data');
    }
}
