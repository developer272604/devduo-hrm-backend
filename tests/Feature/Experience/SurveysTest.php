<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Survey;
use App\Domain\Experience\SurveyService;
use App\Domain\Tenancy\TenantRunner;
use Tests\Feature\Performance\PerformanceTestCase;

class SurveysTest extends PerformanceTestCase
{
    private function createSurvey(array $extra = []): array
    {
        return $this->postJson('/api/v1/experience/surveys', array_replace(['title' => 'Team pulse', 'description' => 'A short anonymous check-in.',
            'minimum_responses' => 5, 'retention_until' => now()->addMonths(6)->format('Y-m-d'), 'questions' => [['prompt' => 'Is workload manageable?']]], $extra))->assertCreated()->json();
    }

    public function test_anonymous_survey_suppresses_small_groups_and_only_exposes_aggregates(): void
    {
        $survey = $this->createSurvey();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $respondents = [$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider];

        foreach (array_slice($respondents, 0, 4) as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        }
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('suppressed', true)->assertJsonMissingPath('response_count')->assertJsonMissingPath('questions');
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/export')->assertUnprocessable();

        $this->actAs($this->outsider);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 5]])->assertUnprocessable();

        $this->signIn($this->admin);
        $result = $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('suppressed', false)->assertJsonPath('response_count', 5)->json();
        $this->assertSame(5, $result['questions'][0]['distribution'][3]['count']);
        $this->assertTrue($result['questions'][0]['distribution'][4]['suppressed']);
        $this->assertArrayNotHasKey('answer', $result);
        $this->assertSame(1, \DB::table('experience_survey_answer_counts')->count());
        $this->assertSame(5, \DB::table('experience_survey_answer_counts')->value('response_count'));
        $this->assertSame(5, \DB::table('experience_survey_participants')->where('submitted', true)->count());
        $export = $this->get('/api/v1/experience/surveys/'.$survey['uuid'].'/export')->assertOk()->assertDownload('survey-aggregates-'.$survey['uuid'].'.csv');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Is workload manageable?', $csv);
        $this->assertStringContainsString(',4,', $csv);
        $this->assertStringNotContainsString('Arun Kumar', $csv);
        $this->assertStringNotContainsString('arun@alpha.test', $csv);
        $this->assertSame(1, \DB::table('audit_logs')->where('action', 'survey.aggregate_exported')->count());

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertNotFound();
    }

    public function test_submission_requires_all_valid_rating_answers_and_employee_profile(): void
    {
        $survey = $this->createSurvey();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertForbidden();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => []])->assertUnprocessable();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 6]])->assertUnprocessable();
    }

    public function test_campaign_eligibility_and_explicit_consent_are_enforced(): void
    {
        $londonEmployee = $this->hire('Lina London', ['location' => 1, 'role' => 'Team member']);
        $survey = $this->createSurvey(['campaign_type' => 'onboarding', 'requires_consent' => true,
            'eligibility' => ['location_ids' => [$this->org['location'][1]]]]) + [];
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/surveys')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4], 'consent' => true])->assertNotFound();

        $this->actAs($londonEmployee);
        $this->getJson('/api/v1/experience/surveys')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.campaign_type', 'onboarding');
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertUnprocessable();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4], 'consent' => true])->assertOk();
        $surveyId = \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id');
        $this->assertDatabaseHas('experience_survey_participants', ['survey_id' => $surveyId, 'consented' => true]);
    }

    public function test_survey_results_expire_and_scheduled_retention_purges_data(): void
    {
        $survey = $this->createSurvey(['retention_until' => now()->addDays(2)->format('Y-m-d')]);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        $surveyId = \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id');
        \DB::table('experience_surveys')->where('id', $surveyId)->update(['retention_until' => now()->subDay()->toDateString()]);
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('retention_expired', true);
        $purged = app(TenantRunner::class)->run($this->admin->tenant_id, fn () => app(SurveyService::class)->purgeExpiredData());
        $this->assertSame(1, $purged);
        $this->assertDatabaseMissing('experience_survey_answer_counts', ['survey_id' => $surveyId]);
        $this->assertDatabaseMissing('experience_survey_participants', ['survey_id' => $surveyId]);
    }

    public function test_confidential_answers_are_encrypted_unlinked_and_aggregated_after_threshold(): void
    {
        $survey = $this->createSurvey(['response_mode' => 'confidential', 'requires_consent' => true]);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $respondents = [$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider];
        foreach ($respondents as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4], 'consent' => true])->assertOk();
            if ($employee === $this->emp2) {
                $this->assertDatabaseMissing('experience_survey_answer_counts', ['survey_id' => \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id')]);
                $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('experience_survey_confidential_responses', 'participant_id'));
                $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('experience_survey_confidential_responses', 'user_id'));
                $ciphertext = \DB::table('experience_survey_confidential_responses')->value('encrypted_answers');
                $this->assertNotSame(json_encode([$survey['questions'][0]['uuid'] => 4]), $ciphertext);
            }
        }
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('response_mode', 'confidential')
            ->assertJsonPath('questions.0.distribution.3.count', 5);
        $this->assertSame(5, \DB::table('experience_survey_confidential_responses')->where('survey_id', \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id'))->count());
    }

    public function test_identified_mode_requires_consent_and_restricts_audited_individual_access(): void
    {
        $this->postJson('/api/v1/experience/surveys', ['title' => 'Identified feedback', 'response_mode' => 'identified', 'retention_until' => now()->addMonths(6)->toDateString(),
            'questions' => [['prompt' => 'What support do you need?']]])->assertUnprocessable();
        $survey = $this->createSurvey(['response_mode' => 'identified', 'requires_consent' => true]);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $respondents = [$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider];
        foreach ($respondents as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4], 'consent' => true])->assertOk();
        }
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/identified-responses')->assertForbidden();
        $this->actAs($this->mgr);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/identified-responses')->assertForbidden();
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/identified-responses')->assertOk()
            ->assertJsonPath('suppressed', false)->assertJsonPath('responses.0.employee', 'Arun Kumar')
            ->assertJsonPath('responses.0.answers.0.value', 4);
        $surveyId = \DB::table('experience_surveys')->where('uuid', $survey['uuid'])->value('id');
        \DB::table('experience_surveys')->where('id', $surveyId)->update(['retention_until' => now()->subDay()->toDateString()]);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/identified-responses')->assertOk()->assertJsonPath('retention_expired', true);
        $this->assertSame(2, \DB::table('audit_logs')->where('action', 'survey.identified_responses_accessed')->count());
        $this->assertSame(5, \DB::table('experience_survey_identified_responses')->where('survey_id', $surveyId)->count());
    }

    public function test_aggregate_export_sanitizes_spreadsheet_formulas_and_disables_caching(): void
    {
        $survey = $this->createSurvey(['questions' => [['prompt' => ' =HYPERLINK("https://invalid.test")']]]);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        foreach ([$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider] as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        }
        $this->signIn($this->admin);
        $export = $this->get('/api/v1/experience/surveys/'.$survey['uuid'].'/export')->assertOk()->assertDownload();
        $this->assertStringContainsString('private', $export->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $this->assertStringContainsString("'=HYPERLINK", $export->streamedContent());
    }
}
