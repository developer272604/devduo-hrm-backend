<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeNote;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Employee notes with four visibility levels. Which notes a viewer may read is decided here and ONLY here, by
 * filtering the query, so a note the viewer may not read behaves exactly like a note that does not exist
 * (no counts, no 403 on a specific id: 404).
 *
 *   general_hr        people.notes.manage
 *   manager           the subject's line manager, or people.notes.manage
 *   private_hr        only people.notes.private
 *   employee_visible  the subject, plus anyone who can read at least one of the other levels
 */
class NoteService
{
    public const MAX_MENTIONS = 20;

    public const MAX_ATTACHMENTS = 10;

    public function __construct(private DomainEvents $events, private DataScope $scope) {}

    /** @return array<int,string> visibility levels the viewer may read on this subject */
    public function readable(User $viewer, Employee $subject): array
    {
        $manage = $viewer->allows('people.notes.manage') && $this->scope->canAccess($viewer, $subject, 'people.notes.manage');
        $private = $viewer->allows('people.notes.private') && $this->scope->canAccess($viewer, $subject, 'people.notes.private');
        $manager = $this->scope->isManagerOf($viewer, $subject);
        $self = $this->scope->isSelf($viewer, $subject);

        $levels = [];
        if ($manage) {
            $levels[] = 'general_hr';
        }
        if ($manage || $manager) {
            $levels[] = 'manager';
        }
        if ($private) {
            $levels[] = 'private_hr';
        }
        if ($self || $manage || $manager || $private) {
            $levels[] = 'employee_visible';
        }

        return $levels;
    }

    /** @return array<int,string> visibility levels the viewer may write (creating a note needs people.notes.manage) */
    public function writable(User $viewer, Employee $subject): array
    {
        if (! $viewer->allows('people.notes.manage') || ! $this->scope->canAccess($viewer, $subject, 'people.notes.manage')) {
            return [];
        }

        return array_values(array_filter(EmployeeNote::VISIBILITIES, fn ($v) => $v !== 'private_hr' || ($viewer->allows('people.notes.private') && $this->scope->canAccess($viewer, $subject, 'people.notes.private'))));
    }

    public function visibleQuery(User $viewer, Employee $subject): Builder
    {
        $levels = $this->readable($viewer, $subject);
        $query = EmployeeNote::query()->where('employee_id', $subject->id);

        return $levels === [] ? $query->whereRaw('1 = 0') : $query->whereIn('visibility', $levels);
    }

    public function list(User $viewer, Employee $subject, array $filters = [])
    {
        return $this->visibleQuery($viewer, $subject)->with('author:id,name')
            ->when(! empty($filters['visibility']), fn (Builder $q) => $q->where('visibility', $filters['visibility']))
            ->when(! empty($filters['category']), fn (Builder $q) => $q->where('category', $filters['category']))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(PersonSection::CAP)->get();
    }

    public function find(User $viewer, Employee $subject, string $uuid): EmployeeNote
    {
        abort_unless(Str::isUuid($uuid), 404);

        return $this->visibleQuery($viewer, $subject)->with('author:id,name')->where('uuid', $uuid)->firstOrFail();
    }

    public function create(User $viewer, Employee $subject, array $data): EmployeeNote
    {
        return DB::transaction(function () use ($viewer, $subject, $data) {
            Employee::query()->whereKey($subject->id)->lockForUpdate()->first();
            $this->assertMayWrite($viewer, $subject, $data['visibility']);
            $note = new EmployeeNote([
                'author_id' => $viewer->id, 'visibility' => $data['visibility'], 'category' => $this->blank($data['category'] ?? null), 'body' => trim($data['body']),
                'mentions' => $this->mentions($data['mentions'] ?? []), 'attachments' => $this->attachments($subject, $data['attachments'] ?? []),
            ]);
            $note->employee_id = $subject->id;
            $note->save();

            Audit::record('note_added', 'people', 'employee:'.$subject->uuid, ['note' => $note->uuid, 'visibility' => $note->visibility, 'category' => $note->category]);
            $this->event($subject, 'NoteAdded', $note, ' added.');

            return $note->load('author:id,name');
        });
    }

    public function update(User $viewer, Employee $subject, string $uuid, array $data): EmployeeNote
    {
        return DB::transaction(function () use ($viewer, $subject, $uuid, $data) {
            Employee::query()->whereKey($subject->id)->lockForUpdate()->first();
            $note = $this->find($viewer, $subject, $uuid);
            $this->assertAuthor($viewer, $note);
            $this->assertMayWrite($viewer, $subject, $data['visibility'] ?? $note->visibility);

            $changes = [];
            foreach (['visibility', 'category', 'body'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                }
            }
            if (array_key_exists('category', $changes)) {
                $changes['category'] = $this->blank($changes['category']);
            }
            if (array_key_exists('mentions', $data)) {
                $changes['mentions'] = $this->mentions($data['mentions'] ?? []);
            }
            if (array_key_exists('attachments', $data)) {
                $changes['attachments'] = $this->attachments($subject, $data['attachments'] ?? []);
            }
            $note->fill($changes);
            $dirty = array_keys($note->getDirty());
            if ($dirty === []) {
                return $note;
            }
            $note->save();

            Audit::record('note_updated', 'people', 'employee:'.$subject->uuid, ['note' => $note->uuid, 'fields' => $dirty, 'visibility' => $note->visibility]);
            $this->event($subject, 'NoteUpdated', $note, ' updated.');

            return $note->refresh()->load('author:id,name');
        });
    }

    public function delete(User $viewer, Employee $subject, string $uuid): void
    {
        DB::transaction(function () use ($viewer, $subject, $uuid) {
            Employee::query()->whereKey($subject->id)->lockForUpdate()->first();
            $note = $this->find($viewer, $subject, $uuid);
            $this->assertAuthor($viewer, $note);
            $this->assertMayWrite($viewer, $subject, $note->visibility);
            $note->delete(); // soft delete

            Audit::record('note_removed', 'people', 'employee:'.$subject->uuid, ['note' => $note->uuid, 'visibility' => $note->visibility]);
            $this->event($subject, 'NoteRemoved', $note, ' removed.');
        });
    }

    // ---- rules --------------------------------------------------------------------------------------

    private function assertMayWrite(User $viewer, Employee $subject, string $visibility): void
    {
        abort_unless(in_array($visibility, $this->writable($viewer, $subject), true), 403, 'You do not have permission to write notes with this visibility.');
    }

    private function assertAuthor(User $viewer, EmployeeNote $note): void
    {
        abort_unless($note->author_id === $viewer->id, 403, 'Only the author of a note can change or delete it.');
    }

    /** @return array<int,string> employee uuids, validated to belong to the tenant */
    private function mentions(array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if ($uuids === []) {
            return [];
        }
        $found = Employee::query()->whereIn('uuid', $uuids)->pluck('uuid')->all();
        if (count($found) !== count($uuids)) {
            throw ValidationException::withMessages(['mentions' => ['One of the people you mentioned could not be found.']]);
        }

        return $uuids;
    }

    /** @return array<int,string> document uuids that belong to the subject's person */
    private function attachments(Employee $subject, array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if ($uuids === []) {
            return [];
        }
        $found = DB::table('employee_documents')->where('tenant_id', app(TenantContext::class)->id)->where('person_id', $subject->person_id)
            ->whereIn('uuid', $uuids)->whereNull('deleted_at')->pluck('uuid')->all();
        if (count($found) !== count($uuids)) {
            throw ValidationException::withMessages(['attachments' => ['One of the attached documents was not found on this employee\'s profile.']]);
        }

        return $uuids;
    }

    private function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * The event reaches the timeline only for the audience that may read the note. Private HR notes are recorded for
     * the audit/automation stream but are system_restricted, so no timeline ever shows that they exist.
     */
    private function event(Employee $subject, string $type, EmployeeNote $note, string $tail): void
    {
        $label = ['general_hr' => 'HR note', 'manager' => 'Manager note', 'private_hr' => 'Note', 'employee_visible' => 'Employee-visible note'][$note->visibility];
        $class = ['general_hr' => Classification::HR_CONFIDENTIAL, 'manager' => Classification::MANAGER_SENSITIVE, 'private_hr' => Classification::SYSTEM_RESTRICTED, 'employee_visible' => Classification::EMPLOYEE][$note->visibility];
        $this->events->record($type, [
            'employee' => $subject, 'summary' => $label.$tail, 'classification' => $class,
            'payload' => ['note' => $note->uuid, 'visibility' => $note->visibility], 'subject' => ['note', $note->id],
        ]);
    }
}
