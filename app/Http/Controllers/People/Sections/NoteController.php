<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeNote;
use App\Domain\People\Sections\NoteService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * /employees/{uuid}/notes. Reading is filtered by NoteService::visibleQuery: notes the viewer may not read do not
 * exist as far as the API is concerned (never counted, never a 403: a direct request returns 404).
 */
class NoteController extends PeopleController
{
    public function __construct(private NoteService $notes) {}

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $filters = $r->validate(['visibility' => ['nullable', Rule::in(EmployeeNote::VISIBILITIES)], 'category' => 'nullable|string|max:60'], ['visibility.in' => 'Choose a valid note visibility.']);
        $rows = $this->notes->list($r->user(), $employee, $filters);
        $names = $this->mentionNames($rows);

        return [
            'data' => $rows->map(fn (EmployeeNote $n) => $this->present($n, $r, $names))->values(),
            'viewer' => ['creatable_visibilities' => $this->notes->writable($r->user(), $employee)],
        ];
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.notes.manage', false);
        $data = $r->validate($this->rules(true), $this->messages());
        $note = $this->notes->create($r->user(), $employee, $data);

        return response()->json($this->present($note, $r, $this->mentionNames(collect([$note]))), 201);
    }

    public function update(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid, 'people.notes.manage', false);
        $this->notes->find($r->user(), $employee, $id); // 404 when the note does not exist or may not be read
        $data = $r->validate($this->rules(false), $this->messages());
        abort_if($data === [], 422, 'No changes were submitted.');
        $note = $this->notes->update($r->user(), $employee, $id, $data);

        return $this->present($note, $r, $this->mentionNames(collect([$note])));
    }

    public function destroy(Request $r, string $uuid, string $id)
    {
        $employee = $this->employee($r, $uuid, 'people.notes.manage', false);
        $this->notes->delete($r->user(), $employee, $id);

        return ['deleted' => true];
    }

    private function rules(bool $create): array
    {
        $required = $create ? ['required'] : ['sometimes', 'required'];

        return [
            'visibility' => [...$required, Rule::in(EmployeeNote::VISIBILITIES)],
            'body' => [...$required, 'string', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'mentions' => ['sometimes', 'nullable', 'array', 'max:'.NoteService::MAX_MENTIONS],
            'mentions.*' => ['uuid', 'distinct'],
            'attachments' => ['sometimes', 'nullable', 'array', 'max:'.NoteService::MAX_ATTACHMENTS],
            'attachments.*' => ['uuid', 'distinct'],
        ];
    }

    private function messages(): array
    {
        return [
            'visibility.required' => 'Choose who can see this note.',
            'visibility.in' => 'The visibility must be general_hr, manager, private_hr or employee_visible.',
            'body.required' => 'Write the note before saving it.',
            'body.max' => 'The note is too long. Keep it under 10,000 characters.',
            'mentions.max' => 'You can mention up to '.NoteService::MAX_MENTIONS.' people in a note.',
            'mentions.*.uuid' => 'A mentioned person is not valid.',
            'attachments.max' => 'You can attach up to '.NoteService::MAX_ATTACHMENTS.' documents to a note.',
            'attachments.*.uuid' => 'An attached document is not valid.',
        ];
    }

    /** @return array<string,string> employee uuid => display name */
    private function mentionNames(Collection $notes): array
    {
        $uuids = $notes->flatMap(fn (EmployeeNote $n) => $n->mentions ?? [])->unique()->values()->all();
        if ($uuids === []) {
            return [];
        }

        return Employee::query()->whereIn('uuid', $uuids)->with('person:id,display_name')->get()->mapWithKeys(fn (Employee $e) => [$e->uuid => $e->person?->display_name])->all();
    }

    private function present(EmployeeNote $note, Request $r, array $names): array
    {
        return [
            'uuid' => $note->uuid, 'visibility' => $note->visibility, 'category' => $note->category, 'body' => $note->body,
            'author' => ['name' => $note->author?->name], 'is_mine' => $note->author_id === $r->user()->id,
            'mentions' => collect($note->mentions ?? [])->map(fn ($u) => ['uuid' => $u, 'name' => $names[$u] ?? null])->values(),
            'attachments' => $note->attachments ?? [],
            'created_at' => $note->created_at?->toIso8601String(), 'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }
}
