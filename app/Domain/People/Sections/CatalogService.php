<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Skill;
use App\Domain\People\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenant skill catalog and tag definitions. Both are deactivated rather than deleted: people keep the skills
 * and tags they were given, and history stays explainable.
 */
class CatalogService
{
    public const TAG_COLORS = ['slate', 'gray', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];

    public function __construct(private DomainEvents $events) {}

    // ---- skills -------------------------------------------------------------------------------------

    public function skillRules(bool $create): array
    {
        $required = $create ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function skills(array $filters): LengthAwarePaginator
    {
        return Skill::query()
            ->when(($filters['q'] ?? null) !== null, fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($filters['q'], '%_\\').'%'))
            ->when(($filters['category'] ?? null) !== null, fn (Builder $q) => $q->where('category', $filters['category']))
            ->when(! filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->where('active', true))
            ->orderBy('name')->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 50))))
            ->through(fn (Skill $s) => $this->presentSkill($s));
    }

    public function createSkill(array $data): Skill
    {
        return DB::transaction(function () use ($data) {
            $this->assertUniqueName(Skill::class, $data['name'], null, 'A skill with this name already exists.');
            $skill = Skill::create(['name' => trim($data['name']), 'category' => $this->blank($data['category'] ?? null), 'active' => $data['active'] ?? true]);
            $this->record('skill', 'created', $skill->id, 'Skill "'.$skill->name.'" was added to the catalog.', array_keys($data));

            return $skill;
        });
    }

    public function updateSkill(int $id, array $data): Skill
    {
        return DB::transaction(function () use ($id, $data) {
            $skill = Skill::query()->lockForUpdate()->findOrFail($id);
            if (isset($data['name'])) {
                $this->assertUniqueName(Skill::class, $data['name'], $skill->id, 'A skill with this name already exists.');
                $data['name'] = trim($data['name']);
            }
            if (array_key_exists('category', $data)) {
                $data['category'] = $this->blank($data['category']);
            }
            $skill->fill($data);
            $dirty = $skill->getDirty();
            if ($dirty) {
                $skill->save();
                $verb = array_key_exists('active', $dirty) ? ($skill->active ? 'reactivated' : 'deactivated') : 'updated';
                $this->record('skill', $verb, $skill->id, 'Skill "'.$skill->name.'" was '.$verb.' in the catalog.', array_keys($dirty));
            }

            return $skill;
        });
    }

    public function presentSkill(Skill $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'active' => (bool) $s->active, 'created_at' => $s->created_at?->toIso8601String()];
    }

    // ---- tags ---------------------------------------------------------------------------------------

    public function tagRules(bool $create): array
    {
        $required = $create ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:80'],
            'color_token' => ['sometimes', 'string', Rule::in(self::TAG_COLORS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'required_permission' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(WorkspaceProvisioner::PERMISSIONS))],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function tagMessages(): array
    {
        return [
            'color_token.in' => 'Choose one of the available tag colors.',
            'required_permission.in' => 'That permission does not exist.',
        ];
    }

    public function createTag(array $data): Tag
    {
        return DB::transaction(function () use ($data) {
            $this->assertUniqueName(Tag::class, $data['name'], null, 'A tag with this name already exists.');
            $tag = Tag::create([
                'name' => trim($data['name']), 'color_token' => $data['color_token'] ?? 'violet', 'description' => $this->blank($data['description'] ?? null),
                'required_permission' => $data['required_permission'] ?? null, 'active' => $data['active'] ?? true,
            ]);
            $this->record('tag', 'created', $tag->id, 'Tag "'.$tag->name.'" was created.', array_keys($data));

            return $tag;
        });
    }

    public function updateTag(int $id, array $data): Tag
    {
        return DB::transaction(function () use ($id, $data) {
            $tag = Tag::query()->lockForUpdate()->findOrFail($id);
            if (isset($data['name'])) {
                $this->assertUniqueName(Tag::class, $data['name'], $tag->id, 'A tag with this name already exists.');
                $data['name'] = trim($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $data['description'] = $this->blank($data['description']);
            }
            $tag->fill($data);
            $dirty = $tag->getDirty();
            if ($dirty) {
                $tag->save();
                $verb = array_key_exists('active', $dirty) ? ($tag->active ? 'reactivated' : 'deactivated') : 'updated';
                $this->record('tag', $verb, $tag->id, 'Tag "'.$tag->name.'" was '.$verb.'.', array_keys($dirty));
            }

            return $tag;
        });
    }

    /** @param  \App\Models\User  $viewer */
    public function tags(array $filters, $viewer): LengthAwarePaginator
    {
        $settings = $viewer->allows('people.settings');

        return Tag::query()
            ->when(($filters['q'] ?? null) !== null, fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($filters['q'], '%_\\').'%'))
            ->when(! filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->where('active', true))
            // Tags restricted to a permission are invisible to people who lack it (unless they administer tags).
            ->when(! $settings, fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('required_permission')->orWhereIn('required_permission', $viewer->permissionKeys())))
            ->orderBy('name')->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 50))))
            ->through(fn (Tag $t) => $this->presentTag($t, $viewer));
    }

    public function presentTag(Tag $t, $viewer = null): array
    {
        $out = ['id' => $t->id, 'name' => $t->name, 'color_token' => $t->color_token, 'description' => $t->description, 'active' => (bool) $t->active];
        if ($viewer) {
            $out['required_permission'] = $viewer->allows('people.settings') ? $t->required_permission : null;
            $out['assignable'] = $t->active && $viewer->allows('people.manage') && $this->mayUse($viewer, $t);
        }

        return $out;
    }

    public function mayUse($user, Tag $tag): bool
    {
        return $tag->required_permission === null || $user->allows($tag->required_permission);
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @param  class-string<Model>  $class */
    private function assertUniqueName(string $class, string $name, ?int $ignoreId, string $message): void
    {
        $exists = $class::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => [$message]]);
        }
    }

    private function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function record(string $kind, string $verb, int $id, string $summary, array $fields): void
    {
        Audit::record($kind.'_'.$verb, 'people', $kind.':'.$id, ['fields' => array_values($fields)]);
        $this->events->record(ucfirst($kind).'Catalog'.ucfirst($verb), [
            'summary' => $summary, 'classification' => Classification::PUBLIC_INTERNAL, 'subject' => [$kind, $id], 'payload' => ['id' => $id],
        ]);
    }
}
