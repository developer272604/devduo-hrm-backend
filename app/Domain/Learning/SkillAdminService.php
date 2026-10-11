<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{EmployeeSkill, ProficiencyLevel, Skill, SkillCategory, SkillEvidence, SkillRequirement};
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Taxonomy administration (categories, skills, proficiency scale), designation skill requirements, and manager validation with evidence. */
final class SkillAdminService
{
    public const EVIDENCE_KINDS = ['observation', 'certificate', 'project', 'assessment', 'other'];

    public function __construct(private SkillService $skills, private LearningAccess $access) {}

    public function categories(): array
    {
        return SkillCategory::orderBy('name')->get()->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'active' => (bool) $c->active, 'skills' => Skill::where('category_id', $c->id)->count()])->all();
    }

    public function saveCategory(array $d, ?SkillCategory $c = null): SkillCategory
    {
        if (! $c && SkillCategory::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A category with this code already exists.']);
        $c = $c ? tap($c)->update(array_intersect_key($d, array_flip(['name', 'active']))) : SkillCategory::create(['code' => $d['code'], 'name' => $d['name'], 'active' => $d['active'] ?? true]);
        Audit::record('skill_category.saved', 'learning', 'skill-category:'.$c->uuid);

        return $c;
    }

    public function presentSkill(Skill $s): array
    {
        return ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description, 'active' => (bool) $s->active, 'category' => $s->category_id ? SkillCategory::whereKey($s->category_id)->first(['uuid', 'name']) : null];
    }

    public function saveSkill(array $d, ?Skill $s = null): Skill
    {
        $data = array_intersect_key($d, array_flip(['name', 'code', 'description', 'active']));
        if (array_key_exists('category_uuid', $d)) {
            $cat = $d['category_uuid'] ? SkillCategory::where('uuid', $d['category_uuid'])->firstOrFail() : null;
            $data['category_id'] = $cat?->id; $data['category'] = $cat?->name;
        }
        if (isset($d['name']) && Skill::where('name', $d['name'])->when($s, fn ($q) => $q->where('id', '!=', $s->id))->exists()) throw ValidationException::withMessages(['name' => 'A skill with this name already exists.']);
        $s = $s ? tap($s)->update($data) : Skill::create($data + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'active' => true]);
        Audit::record('skill.saved', 'learning', 'skill:'.$s->uuid);

        return $s;
    }

    /** Replace the proficiency scale: contiguous ranks from 1, up to 10; a level that is in use cannot be removed. */
    public function saveLevels(array $levels): array
    {
        $ranks = array_column($levels, 'rank'); sort($ranks);
        if ($ranks !== range(1, count($ranks))) throw ValidationException::withMessages(['levels' => 'Ranks must run 1..N without gaps.']);
        $this->skills->levels();
        $max = max($ranks);
        $inUse = max((int) EmployeeSkill::max('level_rank'), (int) SkillRequirement::max('required_rank'), (int) DB::table('learning_course_skills')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->max('grants_rank'));
        if ($max < $inUse) throw ValidationException::withMessages(['levels' => 'Level '.$inUse.' is in use by skills, requirements or courses and cannot be removed.']);
        DB::transaction(function () use ($levels, $max) {
            ProficiencyLevel::where('rank', '>', $max)->delete();
            foreach ($levels as $l) {
                $row = ProficiencyLevel::where('rank', $l['rank'])->first();
                $row ? $row->update(['name' => $l['name'], 'description' => $l['description'] ?? null]) : ProficiencyLevel::create(['rank' => $l['rank'], 'name' => $l['name'], 'description' => $l['description'] ?? null]);
            }
        });
        Audit::record('skill_levels.saved', 'learning', 'proficiency-levels', ['count' => count($levels)]);

        return $this->skills->levels()->map(fn ($l) => ['rank' => $l->rank, 'name' => $l->name, 'description' => $l->description])->all();
    }

    public function requirements(?int $designationId = null): array
    {
        $rows = SkillRequirement::when($designationId, fn ($q) => $q->where('designation_id', $designationId))->orderBy('designation_id')->get();
        $skills = Skill::whereIn('id', $rows->pluck('skill_id'))->get()->keyBy('id');
        $names = DB::table('designations')->whereIn('id', $rows->pluck('designation_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => ['uuid' => $r->uuid, 'designation_id' => (int) $r->designation_id, 'designation' => $names[$r->designation_id] ?? null, 'skill_uuid' => $skills[$r->skill_id]->uuid, 'skill' => $skills[$r->skill_id]->name,
            'required_rank' => (int) $r->required_rank, 'mandatory' => (bool) $r->mandatory])->all();
    }

    public function saveRequirement(User $actor, array $d): SkillRequirement
    {
        if (! DB::table('designations')->where('tenant_id', $actor->tenant_id)->where('id', $d['designation_id'])->exists()) throw ValidationException::withMessages(['designation_id' => 'Unknown designation.']);
        if ($d['required_rank'] > $this->skills->maxRank()) throw ValidationException::withMessages(['required_rank' => 'Choose a level between 1 and '.$this->skills->maxRank().'.']);
        $skill = Skill::where('uuid', $d['skill_uuid'])->where('active', true)->firstOrFail();
        $r = SkillRequirement::where('designation_id', $d['designation_id'])->where('skill_id', $skill->id)->first();
        $data = ['required_rank' => $d['required_rank'], 'mandatory' => $d['mandatory'] ?? true];
        $r = $r ? tap($r)->update($data) : SkillRequirement::create($data + ['designation_id' => $d['designation_id'], 'skill_id' => $skill->id]);
        Audit::record('skill_requirement.saved', 'learning', 'skill-requirement:'.$r->uuid, ['designation' => $d['designation_id'], 'skill' => $skill->uuid, 'rank' => $d['required_rank']]);

        return $r;
    }

    /** Manager validation: sets a verified level and records the evidence in one step (maker-checker enforced by SkillService). */
    public function validate(User $actor, Employee $emp, Skill $skill, int $level, array $evidence): array
    {
        if (mb_strlen(trim($evidence['note'] ?? '')) < 5) throw ValidationException::withMessages(['evidence.note' => 'Describe the evidence (at least 5 characters).']);
        DB::transaction(function () use ($actor, $emp, $skill, $level, $evidence) {
            $this->skills->setLevel($actor, $emp, $skill, $level, 'manager', $evidence['note']);
            SkillEvidence::create(['employee_id' => $emp->id, 'skill_id' => $skill->id, 'kind' => $evidence['kind'] ?? 'observation', 'note' => $evidence['note'], 'reference' => $evidence['reference'] ?? null, 'created_by' => $actor->id]);
        });

        return $this->skills->profile($emp, true);
    }

    public function evidence(Employee $emp, Skill $skill): array
    {
        return SkillEvidence::where('employee_id', $emp->id)->where('skill_id', $skill->id)->latest('id')->get()->map(fn ($e) => ['kind' => $e->kind, 'note' => $e->note, 'reference' => $e->reference, 'at' => $e->created_at->toIso8601String(), 'by' => $this->access->name(null) ?? User::whereKey($e->created_by)->value('name')])->all();
    }
}
