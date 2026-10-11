<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class SurveyDeliveryTest extends PerformanceTestCase
{
    public function test_survey_invites_respect_preferences_and_reminders_stop_after_response_and_cap(): void
    {
        $survey = $this->postJson('/api/v1/experience/surveys', ['title' => 'Quarterly pulse', 'description' => 'Optional feedback.',
            'retention_until' => now()->addMonths(6)->toDateString(), 'reminder_interval_days' => 1, 'max_reminders' => 2,
            'questions' => [['prompt' => 'Is work manageable?']]])->assertCreated()->json();
        $this->actAs($this->emp1);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => ['surveys' => ['in_app' => false]]])->assertOk();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $options = ['--tenant' => (string) $this->admin->tenant_id];
        $this->artisan('experience:deliver-surveys', $options)->assertExitCode(0);

        $surveyId = \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id');
        $emp1UserId = $this->userOf($this->emp1)->id;
        $emp2UserId = $this->userOf($this->emp2)->id;
        $emp1Delivery = \DB::table('experience_survey_deliveries')->where('survey_id', $surveyId)->where('user_id', $emp1UserId)->first();
        $this->assertNotNull($emp1Delivery?->invitation_suppressed_at);
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $emp1UserId, 'title' => 'Employee survey available']);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $emp2UserId, 'title' => 'Employee survey available']);

        $this->travelTo(now()->addDay());
        $this->artisan('experience:deliver-surveys', $options)->assertExitCode(0);
        $this->assertSame(1, (int) \DB::table('experience_survey_deliveries')->where('survey_id', $surveyId)->where('user_id', $emp2UserId)->value('reminder_count'));

        $this->actAs($this->emp2);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        $this->travelTo(now()->addDay());
        $this->artisan('experience:deliver-surveys', $options)->assertExitCode(0);
        $this->assertSame(1, (int) \DB::table('experience_survey_deliveries')->where('survey_id', $surveyId)->where('user_id', $emp2UserId)->value('reminder_count'));

        $this->travelTo(now()->addDay());
        $this->artisan('experience:deliver-surveys', $options)->assertExitCode(0);
        $emp3UserId = $this->userOf($this->emp3)->id;
        $this->assertSame(2, (int) \DB::table('experience_survey_deliveries')->where('survey_id', $surveyId)->where('user_id', $emp3UserId)->value('reminder_count'));
    }
}
