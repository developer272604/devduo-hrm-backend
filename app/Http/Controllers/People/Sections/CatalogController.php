<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Models\Skill;
use App\Domain\People\Models\Tag;
use App\Domain\People\Sections\CatalogService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

/** /people/skills and /people/tags: tenant catalogs (read: people.view, write: people.settings). */
class CatalogController extends PeopleController
{
    public function __construct(private CatalogService $catalog) {}

    public function skills(Request $r)
    {
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:80', 'include_inactive' => 'nullable|boolean', 'per_page' => 'nullable|integer|between:5,100']);

        return $this->catalog->skills($filters);
    }

    public function createSkill(Request $r)
    {
        $skill = $this->catalog->createSkill($r->validate($this->catalog->skillRules(true), ['name.required' => 'Enter the skill name.']));

        return response()->json($this->catalog->presentSkill($skill), 201);
    }

    public function updateSkill(Request $r, int $id)
    {
        Skill::query()->findOrFail($id);
        $data = $r->validate($this->catalog->skillRules(false), ['name.required' => 'The skill name cannot be empty.']);
        abort_if($data === [], 422, 'No changes were submitted.');

        return $this->catalog->presentSkill($this->catalog->updateSkill($id, $data));
    }

    public function tags(Request $r)
    {
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'include_inactive' => 'nullable|boolean', 'per_page' => 'nullable|integer|between:5,100']);

        return $this->catalog->tags($filters, $r->user());
    }

    public function createTag(Request $r)
    {
        $tag = $this->catalog->createTag($r->validate($this->catalog->tagRules(true), $this->catalog->tagMessages() + ['name.required' => 'Enter the tag name.']));

        return response()->json($this->catalog->presentTag($tag, $r->user()), 201);
    }

    public function updateTag(Request $r, int $id)
    {
        Tag::query()->findOrFail($id);
        $data = $r->validate($this->catalog->tagRules(false), $this->catalog->tagMessages() + ['name.required' => 'The tag name cannot be empty.']);
        abort_if($data === [], 422, 'No changes were submitted.');

        return $this->catalog->presentTag($this->catalog->updateTag($id, $data), $r->user());
    }
}
