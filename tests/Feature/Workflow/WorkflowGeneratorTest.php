<?php

namespace Tests\Feature\Workflow;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Performance\PerformanceTestCase;

class WorkflowGeneratorTest extends PerformanceTestCase
{
    private const WF = '/api/v1/workflows/';

    private $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->userWithRole('HR administrator', 'hema@alpha.test', 'Hema Nair');
        $this->signIn($this->admin);
    }

    private function generate(string $text)
    {
        return $this->postJson(self::WF.'generate', ['instruction' => $text]);
    }

    public function test_english_instruction_becomes_a_resolved_draft_with_explained_steps(): void
    {
        $res = $this->generate('When a new employee joins, Hema Nair assigns a laptop within 2 days, then the manager approves the induction, then notify HR')->assertCreated();
        $this->assertSame('english', $res->json('language'));
        $this->assertSame(['type' => 'event', 'event_type' => 'EmployeeCreated'], $res->json('spec.trigger'));
        $steps = $res->json('spec.steps');
        $this->assertSame(['task', 'approval', 'notify'], array_column($steps, 'action'));
        $this->assertSame([$this->hr->id], $steps[0]['assignees']);
        $this->assertSame(48, $steps[0]['timeout_hours']);
        $this->assertSame('manager', $steps[1]['assignment']);
        $this->assertSame([$this->hr->id], $steps[2]['assignees']); // "HR" resolves to the HR administrator role
        $this->assertTrue($res->json('ready_to_save'));
        $this->assertSame([], $res->json('questions'));
        $this->assertNotEmpty($res->json('steps.0.matched'));
        $this->assertStringContainsString('without an AI model', $res->json('limitations'));
    }

    public function test_unknown_owner_is_asked_not_guessed_and_blocks_saving(): void
    {
        $res = $this->generate('When someone resigns, IT collects the laptop')->assertCreated();
        $this->assertSame('EmployeeSeparated', $res->json('spec.trigger.event_type'));
        $this->assertFalse($res->json('ready_to_save'));
        $this->assertCount(1, $res->json('questions'));
        $this->assertSame([], $res->json('spec.steps.0.assignees'));
        $this->postJson(self::WF.'generations/'.$res->json('generation_uuid').'/draft', ['name' => 'Exit', 'spec' => $res->json('spec')])->assertStatus(422);
    }

    public function test_tamil_instruction_is_understood(): void
    {
        $res = $this->generate('புதிய ஊழியர் சேரும்போது, மேலாளர் ஒப்புதல் அளிக்க வேண்டும்')->assertCreated();
        $this->assertSame('tamil', $res->json('language'));
        $this->assertSame('EmployeeCreated', $res->json('spec.trigger.event_type'));
        $this->assertSame('approval', $res->json('spec.steps.0.action'));
        $this->assertSame('manager', $res->json('spec.steps.0.assignment'));
        $this->assertTrue($res->json('ready_to_save'));
    }

    public function test_tanglish_instruction_is_understood_with_duration(): void
    {
        $res = $this->generate('Employee join pannumbodhu, manager approve pannu, apram Hema Nair laptop assign pannu 3 naal')->assertCreated();
        $this->assertSame('tanglish', $res->json('language'));
        $this->assertSame('EmployeeCreated', $res->json('spec.trigger.event_type'));
        $steps = $res->json('spec.steps');
        $this->assertSame(['approval', 'task'], array_column($steps, 'action'));
        $this->assertSame(72, $steps[1]['timeout_hours']);
        $this->assertSame([$this->hr->id], $steps[1]['assignees']);
    }

    public function test_unrecognised_start_defaults_to_manual_with_a_warning_and_wait_needs_a_duration(): void
    {
        $res = $this->generate('Hema Nair reviews the checklist, then wait, then the manager approves')->assertCreated();
        $this->assertSame('manual', $res->json('spec.trigger.type'));
        $this->assertNotEmpty($res->json('warnings'));
        $this->assertSame('wait', $res->json('spec.steps.1.action'));
        $this->assertFalse($res->json('ready_to_save'));
        $this->assertStringContainsString('How long', $res->json('questions.0.question'));
    }

    public function test_a_valid_generation_saves_as_a_draft_that_still_needs_another_administrator_to_publish(): void
    {
        $res = $this->generate('When a new employee joins, Hema Nair assigns a laptop, then the manager approves')->assertCreated();
        $uuid = $res->json('generation_uuid');
        $draft = $this->postJson(self::WF."generations/$uuid/draft", ['name' => 'Joiner setup', 'spec' => $res->json('spec')])->assertCreated()->json();
        $this->assertSame(1, $draft['revision']);
        $this->postJson(self::WF."generations/$uuid/draft", ['name' => 'Again', 'spec' => $res->json('spec')])->assertStatus(409);
        $this->assertSame('drafted', DB::table('workflow_generations')->where('uuid', $uuid)->value('outcome'));
        $this->assertFalse((bool) DB::table('workflow_generations')->where('uuid', $uuid)->value('edited_before_save'));

        // The author cannot publish their own generated draft; a second administrator can.
        $this->postJson(self::WF.'definitions/'.$draft['uuid'].'/publish', ['revision' => 1])->assertForbidden();
        $this->signIn($this->checker);
        $this->postJson(self::WF.'definitions/'.$draft['uuid'].'/publish', ['revision' => 1])->assertOk();
    }

    public function test_edits_before_saving_are_recorded(): void
    {
        $res = $this->generate('When a new employee joins, Hema Nair assigns a laptop')->assertCreated();
        $spec = $res->json('spec');
        $spec['steps'][0]['timeout_hours'] = 10;
        $this->postJson(self::WF.'generations/'.$res->json('generation_uuid').'/draft', ['name' => 'Joiner', 'spec' => $spec])->assertCreated();
        $stats = $this->getJson(self::WF.'generations')->assertOk()->json('stats');
        $this->assertSame(1, $stats['edited_before_save']);
        $this->assertSame(1, $stats['saved_as_draft']);
    }

    public function test_only_workflow_managers_can_generate_and_generations_are_private(): void
    {
        $res = $this->generate('When a new employee joins, Hema Nair assigns a laptop')->assertCreated();
        $this->signIn($this->userOf($this->emp1));
        $this->generate('When a new employee joins, Hema Nair assigns a laptop')->assertForbidden();
        $this->signIn($this->checker);
        $this->postJson(self::WF.'generations/'.$res->json('generation_uuid').'/draft', ['name' => 'Stolen', 'spec' => $res->json('spec')])->assertNotFound();
    }

    public function test_input_is_validated(): void
    {
        $this->generate('short')->assertStatus(422);
        $this->generate(str_repeat('a ', 2000))->assertStatus(422);
    }
}
