<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeTag;
use App\Domain\People\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigning tags to employments. A tag with a required_permission can only be assigned or removed by users who
 * hold that permission, and is invisible on profiles to everyone else. Tags never replace structured fields.
 */
class TagService
{
    public function __construct(private DomainEvents $events, private CatalogService $catalog) {}

    /** @return Collection<int,array> tags on the employment that the viewer may see. */
    public function forEmployee(Employee $employee, User $viewer): Collection
    {
        return EmployeeTag::query()->where('employee_tags.employee_id', $employee->id)->with('tag')
            ->join('tags', 'tags.id', '=', 'employee_tags.tag_id')->orderBy('tags.name')->select('employee_tags.*')->limit(PersonSection::CAP)->get()
            ->filter(fn (EmployeeTag $row) => $this->catalog->mayUse($viewer, $row->tag) || $viewer->allows('people.settings'))
            ->map(fn (EmployeeTag $row) => $this->catalog->presentTag($row->tag, $viewer) + ['assigned_at' => $row->created_at?->toIso8601String()])->values();
    }

    /** @return bool true when the tag was newly assigned, false when it was already on the employee */
    public function assign(Employee $employee, int $tagId, User $actor): bool
    {
        return DB::transaction(function () use ($employee, $tagId, $actor) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $tag = Tag::query()->findOrFail($tagId);
            abort_unless($this->catalog->mayUse($actor, $tag), 403, 'You do not have permission to use this tag.');
            abort_unless($tag->active, 422, 'This tag is no longer active, so it cannot be assigned.');
            if (EmployeeTag::query()->where('employee_id', $employee->id)->where('tag_id', $tag->id)->exists()) {
                return false;
            }
            $row = new EmployeeTag(['tag_id' => $tag->id, 'assigned_by' => $actor->id]);
            $row->employee_id = $employee->id;
            $row->save();

            Audit::record('tag_assigned', 'people', 'employee:'.$employee->uuid, ['tag_id' => $tag->id, 'tag' => $tag->name]);
            $this->events->record('TagAssigned', [
                'employee' => $employee, 'summary' => 'Tag "'.$tag->name.'" assigned.', 'classification' => $this->classification($tag),
                'payload' => ['tag_id' => $tag->id], 'subject' => ['tag', $tag->id],
            ]);

            return true;
        });
    }

    public function unassign(Employee $employee, int $tagId, User $actor): void
    {
        DB::transaction(function () use ($employee, $tagId, $actor) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $tag = Tag::query()->findOrFail($tagId);
            abort_unless($this->catalog->mayUse($actor, $tag), 403, 'You do not have permission to use this tag.');
            $row = EmployeeTag::query()->where('employee_id', $employee->id)->where('tag_id', $tag->id)->firstOrFail();
            $row->delete();

            Audit::record('tag_removed', 'people', 'employee:'.$employee->uuid, ['tag_id' => $tag->id, 'tag' => $tag->name]);
            $this->events->record('TagRemoved', [
                'employee' => $employee, 'summary' => 'Tag "'.$tag->name.'" removed.', 'classification' => $this->classification($tag),
                'payload' => ['tag_id' => $tag->id], 'subject' => ['tag', $tag->id],
            ]);
        });
    }

    /** Events of permission-restricted tags are not for the general timeline audience. */
    private function classification(Tag $tag): string
    {
        return $tag->required_permission ? Classification::HR_CONFIDENTIAL : Classification::MANAGER_SENSITIVE;
    }
}
