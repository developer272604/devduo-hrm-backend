<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\SkillEvidence;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal Talent Opportunity Marketplace. Skill matching is transparent arithmetic over the employee's recorded skill levels. People
 * are discoverable only after they opt in and declare spare hours; placement needs the poster's shortlist AND a release by the person's
 * manager (or HR), and never exceeds the declared spare capacity. Completed work becomes skill EVIDENCE for a validator, it does not raise a level.
 */
final class TalentService
{
    public const KINDS = ['project', 'gig', 'stretch', 'role_trial'];

    private const ACTIVE = ['interested', 'shortlisted', 'awaiting_manager', 'placed'];

    private const RESERVING = ['awaiting_manager', 'placed'];

    private const LEGACY_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    private const TRANSITIONS = [
        'interested' => ['shortlisted', 'declined', 'withdrawn'],
        'shortlisted' => ['awaiting_manager', 'declined', 'withdrawn'],
        'awaiting_manager' => ['placed', 'release_denied', 'withdrawn'],
        'placed' => ['completed', 'ended_early'],
    ];

    public function __construct(private DataScope $scope, private TaskService $tasks) {}

    // ---- identity helpers --------------------------------------------------------------------------------------------------------

    public function employeeOf(User $u): ?object
    {
        return DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $u->tenant_id)->where('people.user_id', $u->id)
            ->whereNull('employees.deleted_at')->whereIn('employees.status', Employee::WORKING)->first(['employees.id', 'employees.uuid', 'employees.person_id', 'people.display_name as name']);
    }

    private function requireEmployee(User $u): object
    {
        return $this->employeeOf($u) ?? throw ValidationException::withMessages(['employee' => 'Only a working employee can use the marketplace.']);
    }

    private function isManager(User $u): bool
    {
        return $u->allows('talent.manage') && $this->scope->hasTenantWide($u, 'talent.manage');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---- profile -----------------------------------------------------------------------------------------------------------------

    public function profile(User $u): array
    {
        $e = $this->requireEmployee($u);
        $p = TalentProfile::where('employee_id', $e->id)->first();
        $committed = $this->committedHours($e->id);

        return ['discoverable' => (bool) $p?->discoverable, 'spare_hours_per_week' => (int) ($p->spare_hours_per_week ?? 0), 'interests' => $p->interests ?? null, 'committed_hours' => $committed,
            'free_hours' => max(0, (int) ($p->spare_hours_per_week ?? 0) - $committed),
            'privacy' => 'Others can find you for opportunities only while discoverable is on. You can turn it off at any time. Your manager still decides on every release.'];
    }

    public function saveProfile(User $u, array $d): array
    {
        $e = $this->requireEmployee($u);
        TalentProfile::updateOrCreate(['employee_id' => $e->id], ['discoverable' => (bool) $d['discoverable'], 'spare_hours_per_week' => (int) $d['spare_hours_per_week'], 'interests' => $d['interests'] ?? null]);
        Audit::record('talent.profile', 'learning', 'employee:'.$e->uuid, ['discoverable' => (bool) $d['discoverable'], 'spare_hours' => (int) $d['spare_hours_per_week']]);

        return $this->profile($u);
    }

    public function committedHours(int $employeeId, ?int $excludeApplication = null): int
    {
        return (int) DB::table('talent_applications as a')->join('talent_opportunities as o', 'o.id', '=', 'a.opportunity_id')->where('a.employee_id', $employeeId)->whereIn('a.status', self::RESERVING)
            ->where('o.status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('o.ends_on')->orWhere('o.ends_on', '>=', TenantClock::today()))
            ->when($excludeApplication, fn ($q) => $q->where('a.id', '!=', $excludeApplication))->sum('a.hours_committed');
    }

    // ---- opportunities -----------------------------------------------------------------------------------------------------------

    public function createOpportunity(User $u, array $d): TalentOpportunity
    {
        return DB::transaction(function () use ($u, $d) {
            $o = TalentOpportunity::create(['posted_by' => $u->id, 'title' => $d['title'], 'description' => $d['description'] ?? null, 'kind' => $d['kind'], 'hours_per_week' => $d['hours_per_week'], 'starts_on' => $d['starts_on'],
                'ends_on' => $d['ends_on'] ?? null, 'closes_on' => $d['closes_on'] ?? null, 'openings' => $d['openings'] ?? 1, 'status' => 'draft']);
            $this->syncSkills($o, $d['skills'] ?? []);
            $this->event($o, null, 'created', null, $u);

            return $o;
        });
    }

    public function updateOpportunity(User $u, TalentOpportunity $o, array $d): TalentOpportunity
    {
        $this->assertOwner($u, $o);
        if (in_array($o->status, ['filled', 'cancelled', 'closed'], true)) {
            $this->fail('status', "A {$o->status} opportunity can no longer be edited.");
        }
        DB::transaction(function () use ($u, $o, $d) {
            $o->update(array_intersect_key($d, array_flip(['title', 'description', 'kind', 'hours_per_week', 'starts_on', 'ends_on', 'closes_on', 'openings'])));
            if (array_key_exists('skills', $d)) {
                $this->syncSkills($o, $d['skills']);
            }
            $this->event($o, null, 'updated', null, $u);
        });

        return $o->refresh();
    }

    private function syncSkills(TalentOpportunity $o, array $skills): void
    {
        $ids = [];
        foreach ($skills as $s) {
            $skill = DB::table('skills')->where('tenant_id', $o->tenant_id)->where('uuid', $s['skill_uuid'])->where('active', true)->first(['id']);
            if (! $skill) {
                $this->fail('skills', 'One of the skills does not exist or is inactive.');
            }
            $ids[] = $skill->id;
            TalentOpportunitySkill::updateOrCreate(['opportunity_id' => $o->id, 'skill_id' => $skill->id], ['required_rank' => (int) $s['required_rank'], 'mandatory' => (bool) ($s['mandatory'] ?? true)]);
        }
        TalentOpportunitySkill::where('opportunity_id', $o->id)->whereNotIn('skill_id', $ids ?: [0])->delete();
    }

    private function assertOwner(User $u, TalentOpportunity $o): void
    {
        if ($o->posted_by !== $u->id && ! $this->isManager($u)) {
            abort(403, 'Only the poster or a talent manager can change this opportunity.');
        }
    }

    public function setStatus(User $u, TalentOpportunity $o, string $to, ?string $reason): TalentOpportunity
    {
        $this->assertOwner($u, $o);
        $allowed = ['draft' => ['open', 'cancelled'], 'open' => ['closed', 'cancelled'], 'closed' => ['open', 'cancelled'], 'filled' => ['closed', 'cancelled'], 'cancelled' => [], ][$o->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            $this->fail('status', "A {$o->status} opportunity cannot become {$to}.");
        }
        if ($to === 'open') {
            if ($o->ends_on && $o->ends_on->lt(TenantClock::today())) {
                $this->fail('ends_on', 'This opportunity has already ended.');
            }
            if (! TalentOpportunitySkill::where('opportunity_id', $o->id)->exists()) {
                $this->fail('skills', 'Add at least one required skill so matching can work.');
            }
        }
        if ($to === 'cancelled') {
            if (TalentApplication::where('opportunity_id', $o->id)->where('status', 'placed')->exists()) {
                $this->fail('status', 'End the current placements first.');
            }
            if (trim((string) $reason) === '') {
                $this->fail('reason', 'A reason is required to cancel.');
            }
            foreach (TalentApplication::where('opportunity_id', $o->id)->whereIn('status', ['interested', 'shortlisted', 'awaiting_manager'])->get() as $a) {
                $a->update(['status' => 'declined', 'decision_reason' => 'Opportunity cancelled: '.$reason, 'closed_at' => now()]);
                $this->tasks->completeBySource('talent', 'application:'.$a->uuid);
            }
        }
        $o->update(['status' => $to]);
        $this->event($o, null, 'status', "{$to}".($reason ? ": $reason" : ''), $u);
        Audit::record('talent.opportunity.'.$to, 'learning', 'opportunity:'.$o->uuid, ['reason' => $reason]);

        return $o->refresh();
    }

    public function find(string $uuid): TalentOpportunity
    {
        return TalentOpportunity::where('uuid', $uuid)->firstOrFail();
    }

    // ---- matching ----------------------------------------------------------------------------------------------------------------

    private function levelNames(int $tenantId): array
    {
        $n = DB::table('learning_proficiency_levels')->where('tenant_id', $tenantId)->pluck('name', 'rank')->all();

        return $n ?: [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert'];
    }

    /** @return array<int,array> employee id => match */
    public function matches(TalentOpportunity $o, array $employeeIds, bool $withCourses = false): array
    {
        $reqs = DB::table('talent_opportunity_skills as r')->join('skills', 'skills.id', '=', 'r.skill_id')->where('r.opportunity_id', $o->id)->get(['r.skill_id', 'r.required_rank', 'r.mandatory', 'skills.name', 'skills.uuid']);
        $levels = $this->levelNames($o->tenant_id);
        $have = [];
        if ($employeeIds && $reqs->isNotEmpty()) {
            $rows = DB::table('employee_skills as es')->join('employees', 'employees.person_id', '=', 'es.person_id')->where('es.tenant_id', $o->tenant_id)->whereIn('employees.id', $employeeIds)
                ->whereIn('es.skill_id', $reqs->pluck('skill_id'))->where('es.verification_status', '!=', 'rejected')
                ->get(['employees.id as employee_id', 'es.skill_id', 'es.level_rank', 'es.proficiency', 'es.verification_status']);
            foreach ($rows as $r) {
                $have[$r->employee_id][$r->skill_id] = ['rank' => (int) ($r->level_rank ?? (self::LEGACY_RANK[$r->proficiency] ?? 0)), 'verified' => $r->verification_status === 'verified'];
            }
        }
        $courses = [];
        if ($withCourses && $reqs->isNotEmpty()) {
            foreach (DB::table('learning_course_skills as cs')->join('learning_courses as c', 'c.id', '=', 'cs.course_id')->where('cs.tenant_id', $o->tenant_id)->where('c.status', 'published')
                ->whereIn('cs.skill_id', $reqs->pluck('skill_id'))->get(['cs.skill_id', 'cs.grants_rank', 'c.title', 'c.code']) as $c) {
                $courses[$c->skill_id][] = ['title' => $c->title, 'code' => $c->code, 'grants_rank' => (int) $c->grants_rank];
            }
        }
        $out = [];
        foreach ($employeeIds as $eid) {
            $weight = 0;
            $credit = 0.0;
            $met = [];
            $gaps = [];
            $eligible = true;
            foreach ($reqs as $r) {
                $w = $r->mandatory ? 2 : 1;
                $h = $have[$eid][$r->skill_id] ?? ['rank' => 0, 'verified' => false];
                $weight += $w;
                $credit += $w * min(1, $h['rank'] / max(1, $r->required_rank));
                $row = ['skill' => $r->name, 'skill_uuid' => $r->uuid, 'required' => $levels[$r->required_rank] ?? (string) $r->required_rank, 'has' => $h['rank'] ? ($levels[$h['rank']] ?? (string) $h['rank']) : 'none', 'mandatory' => (bool) $r->mandatory];
                if ($h['rank'] >= $r->required_rank) {
                    $met[] = $row + ['verified' => $h['verified']];
                } else {
                    $eligible = $eligible && ! $r->mandatory;
                    $gaps[] = $row + ($withCourses ? ['courses' => array_slice(array_values(array_filter($courses[$r->skill_id] ?? [], fn ($c) => $c['grants_rank'] >= $r->required_rank)), 0, 3)] : []);
                }
            }
            $out[$eid] = ['score' => $weight ? round($credit / $weight, 2) : 1.0, 'eligible' => $eligible, 'met' => $met, 'gaps' => $gaps, 'no_requirements' => $reqs->isEmpty()];
        }

        return $out;
    }

    private function openCount(TalentOpportunity $o, array $statuses): int
    {
        return TalentApplication::where('opportunity_id', $o->id)->whereIn('status', $statuses)->count();
    }

    public function presentOpportunity(TalentOpportunity $o, ?array $match = null, ?TalentApplication $mine = null, bool $detail = false): array
    {
        $poster = User::find($o->posted_by);
        $skills = DB::table('talent_opportunity_skills as r')->join('skills', 'skills.id', '=', 'r.skill_id')->where('r.opportunity_id', $o->id)->get(['skills.name', 'skills.uuid', 'r.required_rank', 'r.mandatory']);
        $levels = $this->levelNames($o->tenant_id);

        return ['uuid' => $o->uuid, 'title' => $o->title, 'description' => $o->description, 'kind' => $o->kind, 'status' => $o->status, 'hours_per_week' => $o->hours_per_week,
            'starts_on' => $o->starts_on?->toDateString(), 'ends_on' => $o->ends_on?->toDateString(), 'closes_on' => $o->closes_on?->toDateString(), 'openings' => $o->openings,
            'placed' => $this->openCount($o, ['placed']), 'posted_by' => $poster?->name, 'is_poster' => false,
            'skills' => $skills->map(fn ($s) => ['skill' => $s->name, 'skill_uuid' => $s->uuid, 'required_rank' => (int) $s->required_rank, 'required' => $levels[$s->required_rank] ?? (string) $s->required_rank, 'mandatory' => (bool) $s->mandatory])->all(),
            'match' => $match, 'my_application' => $mine ? ['uuid' => $mine->uuid, 'status' => $mine->status, 'decision_reason' => $mine->decision_reason] : null];
    }

    public function browse(User $u): array
    {
        $e = $this->requireEmployee($u);
        $today = TenantClock::today();
        $rows = TalentOpportunity::where('status', 'open')->where(fn ($q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', $today))->orderBy('starts_on')->limit(100)->get();
        $mine = TalentApplication::where('employee_id', $e->id)->whereIn('opportunity_id', $rows->pluck('id'))->get()->keyBy('opportunity_id');
        $items = $rows->map(fn ($o) => $this->presentOpportunity($o, $this->matches($o, [$e->id], true)[$e->id], $mine->get($o->id)))->sortByDesc(fn ($i) => [$i['match']['eligible'], $i['match']['score']])->values()->all();

        return ['items' => $items, 'profile' => $this->profile($u),
            'limitations' => 'Match scores compare your recorded skill levels with what each opportunity asks for. They are a guide, not a decision: the poster and your manager decide.'];
    }

    public function mine(User $u): array
    {
        $e = $this->requireEmployee($u);

        return ['items' => TalentApplication::where('employee_id', $e->id)->orderByDesc('id')->limit(100)->get()->map(fn ($a) => $this->presentApplication($a, true))->all()];
    }

    public function posted(User $u): array
    {
        $q = TalentOpportunity::query()->orderByDesc('id');
        if (! $this->isManager($u)) {
            $q->where('posted_by', $u->id);
        }

        return ['items' => $q->limit(100)->get()->map(fn ($o) => $this->presentOpportunity($o, null, null) + ['applicants' => $this->openCount($o, self::ACTIVE), 'is_poster' => $o->posted_by === $u->id])->all()];
    }

    public function presentApplication(TalentApplication $a, bool $withOpportunity = false, ?array $match = null): array
    {
        $emp = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $a->employee_id)->first(['employees.uuid', 'people.display_name as name']);
        $o = $withOpportunity ? TalentOpportunity::find($a->opportunity_id) : null;

        return ['uuid' => $a->uuid, 'status' => $a->status, 'note' => $a->note, 'decision_reason' => $a->decision_reason, 'hours_committed' => $a->hours_committed, 'employee' => ['uuid' => $emp->uuid, 'name' => $emp->name],
            'placed_at' => $a->placed_at?->toIso8601String(), 'closed_at' => $a->closed_at?->toIso8601String(), 'attested_skills' => $a->attested_skills, 'match' => $match,
            'opportunity' => $o ? ['uuid' => $o->uuid, 'title' => $o->title, 'hours_per_week' => $o->hours_per_week, 'starts_on' => $o->starts_on?->toDateString(), 'ends_on' => $o->ends_on?->toDateString()] : null];
    }

    /** Applicants plus opted-in discoverable colleagues inside the poster's data scope, ranked by match. */
    public function candidates(User $u, TalentOpportunity $o): array
    {
        $this->assertOwner($u, $o);
        $apps = TalentApplication::where('opportunity_id', $o->id)->whereIn('status', self::ACTIVE)->get();
        $q = DB::table('employees')->join('talent_profiles as tp', 'tp.employee_id', '=', 'employees.id')->where('employees.tenant_id', $u->tenant_id)->whereNull('employees.deleted_at')
            ->whereIn('employees.status', Employee::WORKING)->where('tp.discoverable', true)->where('tp.spare_hours_per_week', '>', 0)->whereNotIn('employees.id', TalentApplication::where('opportunity_id', $o->id)->pluck('employee_id'));
        $discoverable = $this->scope->constrain($q, $u, 'talent.post')->limit(300)->pluck('employees.id')->all();
        $ids = array_values(array_unique([...$apps->pluck('employee_id')->all(), ...$discoverable]));
        $matches = $this->matches($o, $ids);
        $free = fn ($eid) => max(0, (int) TalentProfile::where('employee_id', $eid)->value('spare_hours_per_week') - $this->committedHours($eid));
        $applicants = $apps->map(fn ($a) => $this->presentApplication($a, false, $matches[$a->employee_id]) + ['free_hours' => $free($a->employee_id)])->sortByDesc(fn ($i) => $i['match']['score'])->values()->all();
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $discoverable)->pluck('people.display_name', 'employees.id');
        $uuids = DB::table('employees')->whereIn('id', $discoverable)->pluck('uuid', 'id');
        $found = collect($discoverable)->map(fn ($eid) => ['employee' => ['uuid' => $uuids[$eid], 'name' => $names[$eid]], 'match' => $matches[$eid], 'free_hours' => $free($eid)])
            ->filter(fn ($c) => $c['match']['eligible'])->sortByDesc(fn ($c) => $c['match']['score'])->take(25)->values()->all();

        return ['opportunity' => $this->presentOpportunity($o), 'applicants' => $applicants, 'discoverable' => $found,
            'privacy' => 'Colleagues appear here only if they opted in and declared spare hours. Only people inside your data scope are searched.'];
    }

    // ---- applications ------------------------------------------------------------------------------------------------------------

    public function apply(User $u, TalentOpportunity $o, ?string $note): TalentApplication
    {
        $e = $this->requireEmployee($u);
        if ($o->status !== 'open' || ($o->closes_on && $o->closes_on->lt(TenantClock::today()))) {
            $this->fail('opportunity', 'This opportunity is not open.');
        }
        if (TalentApplication::where('opportunity_id', $o->id)->where('employee_id', $e->id)->exists()) {
            $this->fail('opportunity', 'You have already responded to this opportunity.');
        }
        $a = TalentApplication::create(['opportunity_id' => $o->id, 'employee_id' => $e->id, 'note' => $note, 'status' => 'interested']);
        $this->event($o, $a, 'applied', null, $u);

        return $a;
    }

    private function application(string $uuid): TalentApplication
    {
        return TalentApplication::where('uuid', $uuid)->firstOrFail();
    }

    public function applicationFor(string $uuid): TalentApplication
    {
        return $this->application($uuid);
    }

    private function move(TalentApplication $a, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$a->status] ?? [], true)) {
            $this->fail('status', "A {$a->status} response cannot become {$to}.");
        }
    }

    public function withdraw(User $u, TalentApplication $a): TalentApplication
    {
        $e = $this->requireEmployee($u);
        abort_unless($a->employee_id === $e->id, 404);
        $this->move($a, 'withdrawn');
        $a->update(['status' => 'withdrawn', 'closed_at' => now()]);
        $this->tasks->completeBySource('talent', 'application:'.$a->uuid);
        $this->event($this->opportunityOf($a), $a, 'withdrawn', null, $u);

        return $a->refresh();
    }

    private function opportunityOf(TalentApplication $a): TalentOpportunity
    {
        return TalentOpportunity::findOrFail($a->opportunity_id);
    }

    public function shortlist(User $u, TalentApplication $a): TalentApplication
    {
        $o = $this->opportunityOf($a);
        $this->assertOwner($u, $o);
        $this->move($a, 'shortlisted');
        $a->update(['status' => 'shortlisted']);
        $this->event($o, $a, 'shortlisted', null, $u);

        return $a->refresh();
    }

    public function decline(User $u, TalentApplication $a, string $reason): TalentApplication
    {
        $o = $this->opportunityOf($a);
        $this->assertOwner($u, $o);
        $this->move($a, 'declined');
        if (trim($reason) === '') {
            $this->fail('reason', 'A reason is required.');
        }
        $a->update(['status' => 'declined', 'decision_reason' => $reason, 'closed_at' => now()]);
        $this->event($o, $a, 'declined', $reason, $u);

        return $a->refresh();
    }

    /** Poster asks the person's manager to release them. Checks spare capacity and openings first. */
    public function proposePlacement(User $u, TalentApplication $a): array
    {
        $o = $this->opportunityOf($a);
        $this->assertOwner($u, $o);
        $this->move($a, 'awaiting_manager');
        $this->assertRoomFor($o, $a);
        $manager = $this->managerUserId($a->employee_id);
        $a->update(['status' => 'awaiting_manager', 'hours_committed' => $o->hours_per_week]);
        if ($manager) {
            $this->tasks->createOnce(['assignee_id' => $manager, 'title' => 'Release a team member to "'.$o->title.'"', 'description' => $o->hours_per_week.' hours per week from '.$o->starts_on->toDateString().'.',
                'source_module' => 'talent', 'source_entity' => 'application:'.$a->uuid, 'priority' => 'normal', 'action' => '/learning/talent/approvals', 'metadata' => ['application_uuid' => $a->uuid]]);
        }
        $this->event($o, $a, 'release_requested', $manager ? null : 'No reporting manager on record; a talent manager must decide.', $u);

        return ['application' => $this->presentApplication($a->refresh(), true), 'manager_notified' => $manager !== null];
    }

    private function managerUserId(int $employeeId): ?int
    {
        $today = TenantClock::today();
        $manager = DB::table('employee_reporting_relationships')->where('employee_id', $employeeId)->where('type', 'primary')->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->orderByDesc('effective_from')->value('manager_id');

        return $manager ? (DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $manager)->value('people.user_id') ?: null) : null;
    }

    private function assertRoomFor(TalentOpportunity $o, TalentApplication $a): void
    {
        $spare = (int) TalentProfile::where('employee_id', $a->employee_id)->value('spare_hours_per_week');
        $committed = $this->committedHours($a->employee_id, $a->id);
        if ($spare <= 0) {
            $this->fail('capacity', 'This person has not declared any spare hours.');
        }
        if ($committed + $o->hours_per_week > $spare) {
            $this->fail('capacity', "This person has {$spare} spare hours per week and {$committed} are already committed; this needs {$o->hours_per_week}.");
        }
        $taken = TalentApplication::where('opportunity_id', $o->id)->whereIn('status', self::RESERVING)->where('id', '!=', $a->id)->count();
        if ($taken >= $o->openings) {
            $this->fail('openings', 'All openings are taken or already awaiting release.');
        }
    }

    public function awaitingRelease(User $u): array
    {
        $q = TalentApplication::where('status', 'awaiting_manager')->orderBy('id');
        $rows = $q->limit(200)->get()->filter(fn ($a) => $this->canRelease($u, $a));

        return ['items' => $rows->map(fn ($a) => $this->presentApplication($a, true, $this->matches($this->opportunityOf($a), [$a->employee_id])[$a->employee_id]) + ['free_hours' => max(0, (int) TalentProfile::where('employee_id', $a->employee_id)->value('spare_hours_per_week') - $this->committedHours($a->employee_id, $a->id))])->values()->all()];
    }

    private function canRelease(User $u, TalentApplication $a): bool
    {
        return $this->isManager($u) || ($u->allows('talent.approve') && $this->scope->isManagerOf($u, $a->employee_id));
    }

    public function decideRelease(User $u, TalentApplication $a, bool $approve, ?string $reason): TalentApplication
    {
        if (! $this->canRelease($u, $a)) {
            abort(403, 'Only the employee\'s manager or a talent manager can decide this.');
        }
        $o = $this->opportunityOf($a);
        $this->move($a, $approve ? 'placed' : 'release_denied');
        if (! $approve && trim((string) $reason) === '') {
            $this->fail('reason', 'A reason is required to refuse a release.');
        }
        DB::transaction(function () use ($u, $a, $o, $approve, $reason) {
            if ($approve) {
                $this->assertRoomFor($o, $a);
                $a->update(['status' => 'placed', 'placed_at' => now(), 'release_by' => $u->id, 'release_decided_at' => now(), 'decision_reason' => $reason]);
                if (TalentApplication::where('opportunity_id', $o->id)->where('status', 'placed')->count() >= $o->openings && $o->status === 'open') {
                    $o->update(['status' => 'filled']);
                }
            } else {
                $a->update(['status' => 'release_denied', 'release_by' => $u->id, 'release_decided_at' => now(), 'decision_reason' => $reason, 'closed_at' => now()]);
            }
            $this->tasks->completeBySource('talent', 'application:'.$a->uuid);
            $this->event($o, $a, $approve ? 'released' : 'release_denied', $reason, $u);
            Audit::record('talent.release.'.($approve ? 'approved' : 'denied'), 'learning', 'application:'.$a->uuid, ['reason' => $reason]);
        });

        return $a->refresh();
    }

    /** Poster confirms the work is done and names the skills that were demonstrated. Creates evidence for a validator; never changes a skill level. */
    public function complete(User $u, TalentApplication $a, array $skills, ?string $note): TalentApplication
    {
        $o = $this->opportunityOf($a);
        $this->assertOwner($u, $o);
        $this->move($a, 'completed');
        $allowed = DB::table('talent_opportunity_skills as r')->join('skills', 'skills.id', '=', 'r.skill_id')->where('r.opportunity_id', $o->id)->pluck('skills.id', 'skills.uuid');
        $emp = DB::table('employees')->where('id', $a->employee_id)->value('id');
        DB::transaction(function () use ($u, $a, $o, $skills, $note, $allowed, $emp) {
            $attested = [];
            foreach ($skills as $s) {
                $sid = $allowed[$s] ?? null;
                if (! $sid) {
                    $this->fail('skills', 'You can only attest skills this opportunity asked for.');
                }
                SkillEvidence::create(['employee_id' => $emp, 'skill_id' => $sid, 'kind' => 'project', 'note' => mb_substr('Internal opportunity "'.$o->title.'". '.($note ?? ''), 0, 1000),
                    'reference' => 'talent:application:'.$a->uuid, 'created_by' => $u->id]);
                $attested[] = $s;
            }
            $a->update(['status' => 'completed', 'closed_at' => now(), 'attested_skills' => $attested]);
            $this->event($o, $a, 'completed', $attested ? count($attested).' skill(s) attested as evidence' : null, $u);
            Audit::record('talent.completed', 'learning', 'application:'.$a->uuid, ['skills' => count($attested)]);
        });

        return $a->refresh();
    }

    public function endEarly(User $u, TalentApplication $a, string $reason): TalentApplication
    {
        $o = $this->opportunityOf($a);
        $this->assertOwner($u, $o);
        $this->move($a, 'ended_early');
        if (trim($reason) === '') {
            $this->fail('reason', 'A reason is required.');
        }
        $a->update(['status' => 'ended_early', 'decision_reason' => $reason, 'closed_at' => now()]);
        if ($o->status === 'filled') {
            $o->update(['status' => 'open']);
        }
        $this->event($o, $a, 'ended_early', $reason, $u);

        return $a->refresh();
    }

    public function history(TalentOpportunity $o): array
    {
        return TalentEvent::where('opportunity_id', $o->id)->orderBy('id')->get()->map(fn ($e) => ['type' => $e->type, 'detail' => $e->detail, 'actor' => $e->actor_user_id ? User::find($e->actor_user_id)?->name : 'System', 'at' => $e->created_at?->toIso8601String()])->all();
    }

    private function event(TalentOpportunity $o, ?TalentApplication $a, string $type, ?string $detail, ?User $actor): void
    {
        TalentEvent::create(['opportunity_id' => $o->id, 'application_id' => $a?->id, 'type' => $type, 'detail' => $detail, 'actor_user_id' => $actor?->id, 'created_at' => now()]);
    }
}
