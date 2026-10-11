<?php

namespace App\Http\Controllers\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Models\SavedView;
use App\Domain\People\Operations\DirectoryColumns;
use App\Domain\People\Operations\ExportService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Saved directory views: they store filters, columns and sort, never data. */
class ViewController extends PeopleController
{
    private const MAX_PER_USER = 50;

    public function columns(Request $r)
    {
        return ['data' => DirectoryColumns::forUser($r->user())];
    }

    public function index(Request $r)
    {
        $views = SavedView::query()->where('module', 'people')->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere('shared', true))->orderBy('name')->get();
        $owners = \App\Models\User::query()->whereIn('id', $views->pluck('user_id'))->pluck('name', 'id');

        return ['data' => $views->map(fn ($v) => $this->present($v, $r, $owners[$v->user_id] ?? null))->values()];
    }

    public function show(Request $r, string $uuid)
    {
        return $this->present($this->find($r, $uuid), $r);
    }

    public function store(Request $r)
    {
        $data = $this->validated($r);
        abort_if(SavedView::query()->where('user_id', $r->user()->id)->where('module', 'people')->count() >= self::MAX_PER_USER, 422, 'You can keep up to '.self::MAX_PER_USER.' saved views. Delete one first.');
        $this->assertUniqueName($r, $data['name'], null);
        $view = new SavedView($data + ['module' => 'people']);
        $view->user_id = $r->user()->id;
        $view->save();
        Audit::record('view_created', 'people', 'saved-view:'.$view->uuid, ['shared' => $view->shared]);

        return response()->json($this->present($view, $r), 201);
    }

    public function update(Request $r, string $uuid)
    {
        $view = $this->find($r, $uuid);
        abort_unless($view->user_id === $r->user()->id, 403, 'Shared views can only be changed by their owner.');
        $data = $this->validated($r, true);
        isset($data['name']) && $this->assertUniqueName($r, $data['name'], $view->id);
        $view->fill($data)->save();

        return $this->present($view, $r);
    }

    public function destroy(Request $r, string $uuid)
    {
        $view = $this->find($r, $uuid);
        abort_unless($view->user_id === $r->user()->id, 403, 'Shared views can only be deleted by their owner.');
        $view->delete();

        return response()->noContent();
    }

    private function find(Request $r, string $uuid): SavedView
    {
        return SavedView::query()->where('module', 'people')->where('uuid', $uuid)->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere('shared', true))->firstOrFail();
    }

    private function assertUniqueName(Request $r, string $name, ?int $ignore): void
    {
        $exists = SavedView::query()->where('user_id', $r->user()->id)->where('module', 'people')->where('name', $name)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['You already have a view with this name.']]);
        }
    }

    private function validated(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $r->validate(['name' => [$req, 'string', 'max:120'], 'filters' => 'nullable|array', 'columns' => 'nullable|array|max:40', 'columns.*' => 'string', 'sort' => 'nullable|array',
            'sort.key' => 'required_with:sort|in:'.implode(',', DirectoryColumns::sortKeys()), 'sort.dir' => 'nullable|in:asc,desc', 'shared' => 'sometimes|boolean']);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'Give the view a name.');
        }
        $errors = [];
        $unknown = array_diff(array_keys($data['filters'] ?? []), ExportService::FILTER_KEYS);
        if ($unknown) {
            $errors['filters'] = ['Unknown filter: '.implode(', ', $unknown).'.'];
        }
        foreach ($data['filters'] ?? [] as $key => $value) {
            $flat = is_array($value) ? $value : [$value];
            if (count($flat) > 100 || collect($flat)->contains(fn ($x) => ! is_scalar($x) && $x !== null)) {
                $errors['filters.'.$key] = ['The filter "'.$key.'" has an invalid value.'];
            }
        }
        if (isset($data['columns'])) {
            $allowed = array_column(DirectoryColumns::forUser($r->user()), 'key');
            $bad = array_diff($data['columns'], $allowed);
            $bad && $errors['columns'] = ['Unknown or unavailable column: '.implode(', ', $bad).'.'];
            $data['columns'] = array_values(array_unique($data['columns']));
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        abort_if(! empty($data['shared']) && ! $r->user()->allows('people.settings'), 403, 'Only people administrators can share views.');

        return $data;
    }

    private function present(SavedView $v, Request $r, ?string $owner = null): array
    {
        return ['uuid' => $v->uuid, 'name' => $v->name, 'filters' => $v->filters ?? (object) [], 'columns' => $v->columns, 'sort' => $v->sort, 'shared' => $v->shared, 'owned' => $v->user_id === $r->user()->id,
            'owner' => $owner, 'read_only' => $v->user_id !== $r->user()->id];
    }
}
