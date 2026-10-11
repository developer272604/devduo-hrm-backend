<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Learning\Models\{Course, CourseSkill, EmployeeSkill, ProficiencyLevel, Skill, SkillEvidence, SkillHistory, SkillRequirement};
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Skills taxonomy on top of People skills/employee_skills (extended, not duplicated): proficiency levels, self-declared / manager-validated / assessed levels, evidence, history, role requirements and gap analysis. */
final class SkillService
{
    public const DEFAULT_LEVELS = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert', 5 => 'Master'];
    private const LEGACY = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    public function __construct(private LearningAccess $access, private DomainEvents $events) {}

    /** Tenant proficiency levels (seeded with a default 1-5 scale on first use). */
    public function levels()
    {
        if (! ProficiencyLevel::query()->exists()) foreach (self::DEFAULT_LEVELS as $rank => $name) ProficiencyLevel::create(['rank' => $rank, 'name' => $name]);

        return ProficiencyLevel::orderBy('rank')->get();
    }

    public function maxRank(): int
    {
        return (int) ($this->levels()->max('rank') ?? 5);
    }

    public function ensureUuids(): void
    {
        foreach (Skill::whereNull('uuid')->get() as $s) { $s->uuid = (string) \Illuminate\Support\Str::uuid(); $s->save(); }
    }

    public function rankOf(?object $row): int
    {
        if (! $row) return 0;

        return (int) ($row->level_rank ?? self::LEGACY[$row->proficiency ?? ''] ?? 0);
    }

    public function levelName(int $rank): ?string
    {
        return $rank ? $this->levels()->firstWhere('rank', $rank)?->name : null;
    }

    private function legacyLabel(int $rank): string
    {
        return $rank <= 1 ? 'beginner' : ($rank === 2 ? 'intermediate' : ($rank === 3 ? 'advanced' : 'expert'));
    }

    /** Declare (self), validate (manager) or assess a level. Maker-checker: nobody validates or assesses their own skill. */
    public function setLevel(?User $actor, Employee $emp, Skill $skill, int $rank, string $source, ?string $note = null, bool $raiseOnly = false): ?EmployeeSkill
    {
        if ($rank < 1 || $rank > $this->maxRank()) throw ValidationException::withMessages(['level' => 'Choose a proficiency level between 1 and '.$this->maxRank().'.']);
        if (! $skill->active) throw ValidationException::withMessages(['skill_uuid' => 'This skill is no longer active.']);
        if ($actor) {
            if ($source === 'self') abort_unless($this->access->isSelf($actor, $emp) && $actor->allows('learning.self'), 403, 'You can only declare your own skills.');
            else {
                abort_unless($actor->allows('learning.skills.validate') && $this->access->reaches($actor, $emp, 'learning.skills.validate'), 403, 'You cannot validate skills for this person.');
                abort_if($this->access->isSelf($actor, $emp), 403, 'You cannot validate your own skills.');
            }
        }

        return DB::transaction(function () use ($actor, $emp, $skill, $rank, $source, $note, $raiseOnly) {
            $row = EmployeeSkill::where('person_id', $emp->person_id)->where('skill_id', $skill->id)->lockForUpdate()->first();
            $from = $this->rankOf($row);
            if ($raiseOnly && $from >= $rank) return $row;
            $validated = $source !== 'self';
            $data = ['level_rank' => $rank, 'proficiency' => $this->legacyLabel($rank), 'source' => $source, 'verification_status' => $validated ? 'verified' : 'unverified',
                'validated_by' => $validated ? $actor?->id : null, 'validated_at' => $validated ? now() : null, 'verified_by' => $validated ? $actor?->id : null];
            $row = $row ? tap($row)->update($data) : EmployeeSkill::create($data + ['person_id' => $emp->person_id, 'skill_id' => $skill->id]);
            SkillHistory::create(['employee_id' => $emp->id, 'skill_id' => $skill->id, 'from_rank' => $from ?: null, 'to_rank' => $rank, 'source' => $source, 'actor_id' => $actor?->id, 'note' => $note ? mb_substr($note, 0, 300) : null]);
            Audit::record('skill.'.($validated ? 'validated' : 'declared'), 'learning', 'employee:'.$emp->uuid, ['skill' => $skill->uuid, 'from' => $from, 'to' => $rank, 'source' => $source]);
            $this->events->record('SkillLevelChanged', ['employee' => $emp, 'subject' => ['skill', $skill->id], 'summary' => 'Skill "'.$skill->name.'" is now level '.$rank.' ('.$source.')', 'payload' => ['skill' => $skill->uuid, 'from' => $from, 'to' => $rank, 'source' => $source]]);

            return $row;
        });
    }

    public function addEvidence(User $actor, Employee $emp, Skill $skill, array $d): SkillEvidence
    {
        abort_unless($this->access->selfOrReaches($actor, $emp, 'learning.skills.validate'), 403, 'You cannot add evidence for this person.');
        if (! EmployeeSkill::where('person_id', $emp->person_id)->where('skill_id', $skill->id)->exists()) throw ValidationException::withMessages(['skill_uuid' => 'Declare the skill before adding evidence.']);
        $e = SkillEvidence::create(['employee_id' => $emp->id, 'skill_id' => $skill->id, 'kind' => $d['kind'] ?? 'other', 'note' => $d['note'] ?? null, 'reference' => $d['reference'] ?? null, 'created_by' => $actor->id]);
        Audit::record('skill.evidence_added', 'learning', 'employee:'.$emp->uuid, ['skill' => $skill->uuid]);

        return $e;
    }

    /** Skill profile of one employee: current level, source, validation state, evidence count, optional history. */
    public function profile(Employee $emp, bool $history = false): array
    {
        $this->ensureUuids();
        $rows = EmployeeSkill::where('person_id', $emp->person_id)->get();
        $skills = Skill::whereIn('id', $rows->pluck('skill_id'))->get()->keyBy('id');
        $evidence = SkillEvidence::where('employee_id', $emp->id)->selectRaw('skill_id, count(*) c')->groupBy('skill_id')->pluck('c', 'skill_id');
        $out = $rows->map(function ($r) use ($skills, $evidence, $emp, $history) {
            $s = $skills[$r->skill_id]; $rank = $this->rankOf($r);
            $row = ['skill_uuid' => $s->uuid, 'skill' => $s->name, 'level' => $rank, 'level_name' => $this->levelName($rank), 'source' => $r->source ?? 'self', 'validated' => $r->verification_status === 'verified',
                'validated_at' => $r->validated_at?->toIso8601String(), 'evidence_count' => (int) ($evidence[$r->skill_id] ?? 0)];
            if ($history) {
                $row['history'] = SkillHistory::where('employee_id', $emp->id)->where('skill_id', $r->skill_id)->orderBy('id')->get()
                    ->map(fn ($h) => ['from' => $h->from_rank, 'to' => $h->to_rank, 'source' => $h->source, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->all();
            }

            return $row;
        })->sortBy('skill')->values()->all();

        return ['employee_uuid' => $emp->uuid, 'skills' => $out];
    }

    /** Gap analysis against the requirements of the employee's current designation, with courses that grant the missing level. */
    public function gap(Employee $emp): array
    {
        $this->ensureUuids();
        $designation = $this->access->assignmentRow($emp->id)?->designation_id;
        $reqs = $designation ? SkillRequirement::where('designation_id', $designation)->get() : collect();
        $have = EmployeeSkill::where('person_id', $emp->person_id)->get()->keyBy('skill_id');
        $skills = Skill::whereIn('id', $reqs->pluck('skill_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($reqs as $r) {
            $cur = $this->rankOf($have[$r->skill_id] ?? null); $gap = max(0, $r->required_rank - $cur);
            $courses = $gap ? Course::where('status', 'published')->whereIn('id', CourseSkill::where('skill_id', $r->skill_id)->where('grants_rank', '>=', $r->required_rank)->select('course_id'))->get()
                ->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'title' => $c->title])->all() : [];
            $rows[] = ['skill_uuid' => $skills[$r->skill_id]->uuid, 'skill' => $skills[$r->skill_id]->name, 'required' => (int) $r->required_rank, 'current' => $cur, 'gap' => $gap, 'mandatory' => (bool) $r->mandatory, 'suggested_courses' => $courses];
        }
        usort($rows, fn ($a, $b) => $b['gap'] <=> $a['gap'] ?: strcmp($a['skill'], $b['skill']));

        return ['employee_uuid' => $emp->uuid, 'designation_id' => $designation, 'gaps' => $rows, 'gap_count' => count(array_filter($rows, fn ($r) => $r['gap'] > 0)), 'requirements' => count($rows)];
    }

    /** Aggregated gaps for employees in scope: per skill, how many people are below the requirement. */
    public function teamGaps(User $viewer, string $permission = 'learning.view'): array
    {
        $ids = $this->access->reachIds($viewer, $permission)->pluck('id')->all();
        $by = [];
        foreach (Employee::whereIn('id', $ids)->get() as $emp) {
            foreach ($this->gap($emp)['gaps'] as $g) {
                $k = $g['skill_uuid']; $by[$k] ??= ['skill_uuid' => $k, 'skill' => $g['skill'], 'required' => $g['required'], 'people' => 0, 'below' => 0];
                $by[$k]['people']++;
                if ($g['gap'] > 0) $by[$k]['below']++;
            }
        }
        $out = array_values($by); usort($out, fn ($a, $b) => $b['below'] <=> $a['below']);

        return $out;
    }
}
