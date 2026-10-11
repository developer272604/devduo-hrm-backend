<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class SurveyCatalogTest extends PerformanceTestCase
{
    public function test_tenant_survey_bank_and_scale_are_snapshotted_into_anonymous_campaigns(): void
    {
        $question = $this->postJson('/api/v1/experience/survey-catalog/questions', ['prompt' => 'I have the tools to do my work.', 'category' => 'resources'])
            ->assertCreated()->json();
        $this->postJson('/api/v1/experience/survey-catalog/scales', ['code' => 'THREE', 'name' => 'Three point agreement', 'minimum_value' => 1,
            'maximum_value' => 3, 'labels' => ['Disagree', 'Neutral', 'Agree']])->assertCreated();
        $this->postJson('/api/v1/experience/survey-catalog/scales', ['code' => 'BAD', 'name' => 'Bad labels', 'minimum_value' => 1,
            'maximum_value' => 3, 'labels' => ['Only one']])->assertUnprocessable();

        $survey = $this->postJson('/api/v1/experience/surveys', ['title' => 'Tools pulse', 'retention_until' => now()->addMonths(6)->toDateString(),
            'scale_code' => 'THREE', 'questions' => [['bank_question_uuid' => $question['uuid']]]])->assertCreated()
            ->assertJsonPath('questions.0.prompt', 'I have the tools to do my work.')
            ->assertJsonPath('questions.0.scale', [1, 2, 3])
            ->assertJsonPath('questions.0.scale_labels.1', 'Disagree')->json();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();

        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertUnprocessable();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 3]])->assertOk();
        $this->assertDatabaseHas('experience_survey_answer_counts', ['answer' => 3, 'response_count' => 1]);
        $this->assertDatabaseMissing('experience_survey_answer_counts', ['answer' => 4]);

        $this->signIn($this->admin);
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/survey-catalog/questions')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/experience/survey-catalog/scales')->assertOk()->assertJsonMissing(['code' => 'THREE']);
    }
}
