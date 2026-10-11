<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class KnowledgeBaseTest extends PerformanceTestCase
{
    private function article(array $data = []): array
    {
        return $this->postJson('/api/v1/experience/knowledge', $data + ['locale' => 'en', 'title' => 'Payslip guidance', 'summary' => 'Understand your payslip', 'body' => 'Open the payroll tab to review your payslip.'])
            ->assertCreated()->json();
    }

    public function test_employees_search_only_effective_published_employee_articles(): void
    {
        $draft = $this->article();
        $internal = $this->article(['title' => 'HR payroll handling', 'visibility' => 'hr']);
        $public = $this->article(['title' => 'Payslip access help', 'summary' => 'Where to find payslips']);
        $this->postJson('/api/v1/experience/knowledge/'.$internal['uuid'].'/publish')->assertOk();
        $this->postJson('/api/v1/experience/knowledge/'.$public['uuid'].'/publish')->assertOk();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/knowledge?search=payslip')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $public['uuid']);
        $this->getJson('/api/v1/experience/knowledge/'.$draft['uuid'])->assertNotFound();
        $this->getJson('/api/v1/experience/knowledge/'.$internal['uuid'])->assertNotFound();
    }

    public function test_publishing_a_new_version_switches_on_its_effective_date(): void
    {
        $v1 = $this->article(['article_key' => 'leave-policy', 'title' => 'Leave policy']);
        $this->postJson('/api/v1/experience/knowledge/'.$v1['uuid'].'/publish')->assertOk();
        $v2 = $this->article(['article_key' => 'leave-policy', 'title' => 'Leave policy updated', 'body' => 'Updated guidance.', 'effective_from' => now()->addDay()->format('Y-m-d')]);
        $this->assertSame(2, $v2['version']);
        $this->postJson('/api/v1/experience/knowledge/'.$v2['uuid'].'/publish')->assertOk();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/knowledge?search=leave')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $v1['uuid']);
        $this->travelTo(now()->addDays(2));
        $this->getJson('/api/v1/experience/knowledge?search=leave')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $v2['uuid']);
        $this->getJson('/api/v1/experience/knowledge/'.$v1['uuid'])->assertNotFound();
    }

    public function test_knowledge_feedback_uses_small_group_suppression_and_no_individual_feedback_is_returned(): void
    {
        $article = $this->article();
        $this->postJson('/api/v1/experience/knowledge/'.$article['uuid'].'/publish')->assertOk();
        $employees = [$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider];
        foreach (array_slice($employees, 0, 1) as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/knowledge/'.$article['uuid'].'/feedback', ['helpful' => true])->assertOk()->assertJsonPath('feedback_given', true);
        }
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/knowledge/'.$article['uuid'].'/feedback-results')->assertOk()->assertJsonPath('suppressed', true)->assertJsonMissingPath('response_count');

        foreach (array_slice($employees, 1) as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/knowledge/'.$article['uuid'].'/feedback', ['helpful' => false])->assertOk();
        }
        $this->signIn($this->admin);
        $results = $this->getJson('/api/v1/experience/knowledge/'.$article['uuid'].'/feedback-results')->assertOk()->assertJsonPath('response_count', 5)->json();
        $this->assertNull($results['helpful']);
        $this->assertNull($results['not_helpful']);
        $this->assertTrue($results['helpful_suppressed']);
        $this->assertTrue($results['not_helpful_suppressed']);
    }
}
