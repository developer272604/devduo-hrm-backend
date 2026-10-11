<?php

namespace Tests\Feature\People;

use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\EmployeeNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SectionsFixtures;
use Tests\TestCase;

class SectionsNotesTagsTest extends TestCase
{
    use RefreshDatabase, SectionsFixtures;

    /** @var array<string,string> visibility => note uuid */
    private array $notes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSections();
    }

    private function seedNotes(): void
    {
        $this->as($this->hrFull);
        foreach (['general_hr', 'manager', 'private_hr', 'employee_visible'] as $v) {
            $this->notes[$v] = $this->postJson($this->path('notes'), ['visibility' => $v, 'body' => "Body $v", 'category' => 'check-in'])->assertCreated()->json('uuid');
        }
    }

    private function visible(): array
    {
        return collect($this->getJson($this->path('notes'))->assertOk()->json('data'))->pluck('visibility')->sort()->values()->all();
    }

    public function test_note_visibility_matrix_without_existence_leaks(): void
    {
        $this->seedNotes();
        $this->as($this->hrFull);
        $this->assertSame(['employee_visible', 'general_hr', 'manager', 'private_hr'], $this->visible());
        $this->signIn($this->admin);
        $this->assertCount(4, $this->visible());

        $this->as($this->hrLimited);
        $this->assertSame(['employee_visible', 'general_hr', 'manager'], $this->visible());
        $this->getJson($this->path('notes/'.$this->notes['private_hr']))->assertStatus(405);
        $this->patchJson($this->path('notes/'.$this->notes['private_hr']), ['body' => 'x'])->assertNotFound();
        $this->deleteJson($this->path('notes/'.$this->notes['private_hr']))->assertNotFound();
        $this->postJson($this->path('notes'), ['visibility' => 'private_hr', 'body' => 'x'])->assertForbidden();

        $this->as($this->boss);
        $this->assertSame(['employee_visible', 'manager'], $this->visible());
        $this->postJson($this->path('notes'), ['visibility' => 'manager', 'body' => 'x'])->assertForbidden();
        $this->as($this->emp);
        $this->assertSame(['employee_visible'], $this->visible());
        $this->postJson($this->path('notes'), ['visibility' => 'employee_visible', 'body' => 'x'])->assertForbidden();
        $this->as($this->peer)->getJson($this->path('notes'))->assertNotFound();
    }

    public function test_only_the_author_edits_or_deletes_and_deleted_notes_disappear(): void
    {
        $this->seedNotes();
        $this->as($this->hrLimited)->patchJson($this->path('notes/'.$this->notes['general_hr']), ['body' => 'x'])->assertForbidden();
        $this->as($this->hrFull)->patchJson($this->path('notes/'.$this->notes['general_hr']), ['body' => 'Edited'])->assertOk()->assertJsonPath('body', 'Edited')->assertJsonPath('is_mine', true);
        $this->deleteJson($this->path('notes/'.$this->notes['general_hr']))->assertOk();
        $this->assertNotContains('general_hr', $this->visible());
        $this->inTenant();
        $this->assertSame(1, EmployeeNote::withTrashed()->where('uuid', $this->notes['general_hr'])->whereNotNull('deleted_at')->count());
        $this->assertAudited('note_updated');
        $this->assertAudited('note_removed');
    }

    public function test_note_events_never_carry_the_body_and_private_notes_stay_off_timelines(): void
    {
        $this->seedNotes();
        $this->inTenant();
        $private = DomainEvent::where('type', 'NoteAdded')->where('payload->visibility', 'private_hr')->first();
        $this->assertSame('system_restricted', $private->classification);
        $this->assertStringNotContainsString('Body', $private->summary);
        $this->assertSame('hr_confidential', \App\Domain\Events\DomainEvent::where('type', 'NoteAdded')->where('payload->visibility', 'general_hr')->value('classification'));
    }

    public function test_mentions_and_attachments_are_validated(): void
    {
        $this->as($this->hrFull);
        $this->postJson($this->path('notes'), ['visibility' => 'general_hr', 'body' => 'x', 'mentions' => [(string) \Illuminate\Support\Str::uuid()]])->assertStatus(422);
        $this->postJson($this->path('notes'), ['visibility' => 'general_hr', 'body' => 'x', 'attachments' => [(string) \Illuminate\Support\Str::uuid()]])->assertStatus(422);
        $this->postJson($this->path('notes'), ['visibility' => 'general_hr', 'body' => '', 'visibility_x' => 1])->assertStatus(422);
        $res = $this->postJson($this->path('notes'), ['visibility' => 'general_hr', 'body' => 'cc', 'mentions' => [$this->boss->uuid]])->assertCreated();
        $this->assertSame('Boss One', $res->json('mentions.0.name'));
    }

    public function test_tags_catalog_and_required_permission(): void
    {
        $this->as($this->hrLimited)->postJson('/api/v1/people/tags', ['name' => 'Remote'])->assertForbidden();
        $this->signIn($this->admin);
        $open = $this->postJson('/api/v1/people/tags', ['name' => 'Remote', 'color_token' => 'teal'])->assertCreated()->json('id');
        $pip = $this->postJson('/api/v1/people/tags', ['name' => 'PIP', 'required_permission' => 'people.notes.private'])->assertCreated()->json('id');
        $this->postJson('/api/v1/people/tags', ['name' => 'remote'])->assertStatus(422);
        $this->postJson('/api/v1/people/tags', ['name' => 'Bad', 'color_token' => 'plaid'])->assertStatus(422);
        $this->postJson('/api/v1/people/tags', ['name' => 'Bad', 'required_permission' => 'nope.nope'])->assertStatus(422);
        $off = $this->postJson('/api/v1/people/tags', ['name' => 'Old'])->json('id');
        $this->patchJson("/api/v1/people/tags/$off", ['active' => false])->assertOk();

        $this->as($this->hrLimited);
        $names = collect($this->getJson('/api/v1/people/tags')->json('data'))->pluck('name')->all();
        $this->assertSame(['Remote'], $names, 'restricted and inactive tags are not offered');
        $this->postJson($this->path("tags/$open"))->assertCreated();
        $this->postJson($this->path("tags/$open"))->assertOk(); // idempotent
        $this->postJson($this->path("tags/$pip"))->assertForbidden();
        $this->postJson($this->path("tags/$off"))->assertStatus(422);
        $this->as($this->boss)->postJson($this->path("tags/$open"))->assertForbidden(); // lacks people.manage

        $this->as($this->hrFull)->postJson($this->path("tags/$pip"))->assertCreated();
        $this->assertCount(2, $this->getJson($this->path('tags'))->json('data'));
        $this->as($this->hrLimited);
        $this->assertSame(['Remote'], collect($this->getJson($this->path('tags'))->json('data'))->pluck('name')->all());
        $this->deleteJson($this->path("tags/$pip"))->assertForbidden();
        $this->as($this->hrFull)->deleteJson($this->path("tags/$pip"))->assertOk();
        $this->assertAudited('tag_assigned');
        $this->assertAudited('tag_removed');
        $this->assertEvent('TagAssigned');
    }
}
