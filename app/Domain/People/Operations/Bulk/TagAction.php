<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Tag;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Sections\TagService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TagAction implements BulkAction, DescribesBulkAction
{
    public function __construct(private bool $add) {}

    public function key(): string
    {
        return $this->add ? 'add_tag' : 'remove_tag';
    }

    public function label(): string
    {
        return $this->add ? 'Add tag' : 'Remove tag';
    }

    public function description(): string
    {
        return ($this->add ? 'Add' : 'Remove').' a tag on the selected employees.';
    }

    public function permission(): string
    {
        return 'people.manage';
    }

    public function validate(array $params): array
    {
        return Validator::make($params, ['tag_id' => 'required|integer'], ['tag_id.required' => 'Choose a tag.'])->validate();
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $tag = Tag::query()->find($params['tag_id']);
        $error = match (true) {
            ! $tag => 'The chosen tag was not found.',
            $this->add && ! $tag->active => 'This tag is no longer active.',
            $tag->required_permission && ! $user->allows($tag->required_permission) => 'You do not have permission to use this tag.',
            default => null,
        };
        $has = $tag ? DB::table('employee_tags')->where('tag_id', $tag->id)->whereIn('employee_id', $employees->pluck('id'))->pluck('employee_id')->flip() : collect();
        $out = [];
        foreach ($employees as $e) {
            $out[$e->id] = match (true) {
                $error !== null => ['status' => 'error', 'reason' => $error],
                $this->add && $has->has($e->id) => ['status' => 'skipped', 'reason' => 'Already tagged.'],
                ! $this->add && ! $has->has($e->id) => ['status' => 'skipped', 'reason' => 'Does not have this tag.'],
                default => ['status' => 'ok'],
            };
        }

        return $out;
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        $tags = app(TagService::class);
        $this->add ? $tags->assign($employee, (int) $params['tag_id'], $user) : $tags->unassign($employee, (int) $params['tag_id'], $user);
    }
}
