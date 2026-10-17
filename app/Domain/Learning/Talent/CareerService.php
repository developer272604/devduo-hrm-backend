<?php

namespace App\Domain\Learning\Talent;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Career Opportunity Navigator (self-service). Compares the employee's recorded skill levels with the skill requirements configured for
 * every other designation, and shows which roles they already fit, which gaps remain, and which published courses and open internal
 * opportunities could close them. It describes fit against configured requirements. It does not promise a promotion or a move.
 */
final class CareerService
{
    private const LEGACY_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    public function __construct(private TalentService $talent) {}

    public function forSelf(User $u): array
    {
        $e = $this->talent->employeeOf($u) ?? abort(422, 'Only a working employee has a career view.');
        $tenant = $u->tenant_id;
        $current = DB::table('employee_assignments')->where('employee_id', $e->id)->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))->orderByDesc('effective_from')->value('designation_id');
        $levels = DB::table('learning_proficiency_levels')->where('tenant_id', $tenant)->pluck('name', 'rank')->all() ?: [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert'];
        $have = [];
        foreach (DB::table('employee_skills')->where('tenant_id', $tenant)->where('person_id', $e->person_id)->where('verification_status', '!=', 'rejected')->get() as $s) {
            $have[$s->skill_id] = (int) ($s->level_rank ?? (self::LEGACY_RANK[$s->proficiency] ?? 0));
        }
        $reqs = DB::table('learning_skill_requirements as r')->join('skills', 'skills.id', '=', 'r.skill_id')->join('designations as d', 'd.id', '=', 'r.designation_id')->where('r.tenant_id', $tenant)
            ->get(['r.designation_id', 'd.name as role', 'r.skill_id', 'skills.name as skill', 'skills.uuid as skill_uuid', 'r.required_rank', 'r.mandatory'])->groupBy('designation_id');
        $courses = DB::table('learning_course_skills as cs')->join('learning_courses as c', 'c.id', '=', 'cs.course_id')->where('cs.tenant_id', $tenant)->where('c.status', 'published')->get(['cs.skill_id', 'cs.grants_rank', 'c.title', 'c.code'])->groupBy('skill_id');
        $opps = DB::table('talent_opportunity_skills as os')->join('talent_opportunities as o', 'o.id', '=', 'os.opportunity_id')->where('o.tenant_id', $tenant)->where('o.status', 'open')
            ->get(['os.skill_id', 'o.uuid', 'o.title'])->groupBy('skill_id');
        $roles = [];
        foreach ($reqs as $designation => $rows) {
            if ((int) $designation === (int) $current) {
                continue;
            }
            $weight = 0;
            $credit = 0.0;
            $met = [];
            $gaps = [];
            $ready = true;
            foreach ($rows as $r) {
                $w = $r->mandatory ? 2 : 1;
                $rank = $have[$r->skill_id] ?? 0;
                $weight += $w;
                $credit += $w * min(1, $rank / max(1, $r->required_rank));
                if ($rank >= $r->required_rank) {
                    $met[] = $r->skill;

                    continue;
                }
                $ready = $ready && ! $r->mandatory;
                $gaps[] = ['skill' => $r->skill, 'required' => $levels[$r->required_rank] ?? (string) $r->required_rank, 'current' => $rank ? ($levels[$rank] ?? (string) $rank) : 'none', 'mandatory' => (bool) $r->mandatory,
                    'courses' => collect($courses[$r->skill_id] ?? [])->filter(fn ($c) => $c->grants_rank >= $r->required_rank)->take(3)->map(fn ($c) => ['title' => $c->title, 'code' => $c->code])->values()->all(),
                    'opportunities' => collect($opps[$r->skill_id] ?? [])->unique('uuid')->take(3)->map(fn ($o) => ['uuid' => $o->uuid, 'title' => $o->title])->values()->all()];
            }
            $roles[] = ['designation' => $rows[0]->role, 'readiness' => $weight ? round($credit / $weight, 2) : 0.0, 'ready_now' => $ready, 'requirements' => $rows->count(), 'skills_met' => $met, 'gaps' => $gaps];
        }
        usort($roles, fn ($a, $b) => [$b['ready_now'], $b['readiness'], $a['designation']] <=> [$a['ready_now'], $a['readiness'], $b['designation']]);

        return ['current_designation' => $current ? DB::table('designations')->where('id', $current)->value('name') : null, 'roles' => $roles,
            'skills_on_record' => count($have),
            'limitations' => 'Fit is measured against the skill requirements your company has configured for each role and the skill levels recorded on your profile. It is not a promise of a move or promotion, and roles without configured requirements are not listed. Keep your skills up to date for a fair picture.'];
    }
}
