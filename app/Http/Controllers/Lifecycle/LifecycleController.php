<?php

namespace App\Http\Controllers\Lifecycle;

use App\Domain\Access\DataScope;
use App\Domain\Lifecycle\{CaseService, MoveService, OffboardingService, TemplateService};
use App\Domain\Lifecycle\Models\{Alumnus, ExitInterview, LifecycleCase, LifecycleTask, Move, Template, TemplateVersion};
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LifecycleController
{
    public function __construct(private TemplateService $templates, private CaseService $cases, private MoveService $moves, private OffboardingService $off) {}

    // ---- templates ------------------------------------------------------------------------------------------------------------------

    private const TASK_RULES = ['tasks' => 'required|array|min:1|max:200', 'tasks.*.title' => 'required|string|max:200', 'tasks.*.group_name' => 'nullable|string|max:80', 'tasks.*.description' => 'nullable|string|max:2000',
        'tasks.*.owner_user_id' => 'nullable|integer', 'tasks.*.due_offset_days' => 'nullable|integer|between:-365,365', 'tasks.*.mandatory' => 'nullable|boolean', 'tasks.*.clearance_dept' => 'nullable|string|max:40',
        'tasks.*.requires_document' => 'nullable|boolean', 'conditions' => 'nullable|array', 'conditions.country' => 'nullable|string|max:2', 'conditions.legal_entity_id' => 'nullable|integer',
        'conditions.worker_type' => 'nullable|string|max:40', 'conditions.separation_type' => 'nullable|string|max:40'];

    private function taskRules(): array
    {
        return self::TASK_RULES + ['tasks.*.owner_type' => ['required', Rule::in(TemplateService::OWNERS)], 'tasks.*.due_anchor' => ['nullable', Rule::in(TemplateService::ANCHORS)]];
    }

    public function templates(Request $r)
    {
        return Template::when($r->query('kind'), fn ($q, $k) => $q->where('kind', $k))->orderBy('kind')->orderBy('name')->get()->map(fn ($t) => $this->templates->present($t));
    }

    public function createTemplate(Request $r)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(TemplateService::KINDS)], 'name' => 'required|string|max:160'] + $this->taskRules());

        return response()->json($this->templates->present($this->templates->create($r->user(), $d)), 201);
    }

    public function showTemplate(string $uuid)
    {
        return $this->templates->present(Template::where('uuid', $uuid)->firstOrFail());
    }

    public function addVersion(Request $r, string $uuid)
    {
        $t = Template::where('uuid', $uuid)->firstOrFail();
        $this->templates->addVersion($r->user(), $t, $r->validate($this->taskRules()));

        return response()->json($this->templates->present($t), 201);
    }

    public function publishVersion(Request $r, string $uuid)
    {
        $v = $this->templates->publish($r->user(), TemplateVersion::where('uuid', $uuid)->firstOrFail());

        return ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status];
    }

    // ---- cases ----------------------------------------------------------------------------------------------------------------------

    private function scopedCases(Request $r, string $permission = 'lifecycle.view')
    {
        return app(DataScope::class)->constrain(LifecycleCase::query(), $r->user(), $permission, 'lifecycle_cases.employee_id');
    }

    private function case(Request $r, string $uuid, string $permission = 'lifecycle.view'): LifecycleCase
    {
        return $this->scopedCases($r, $permission)->where('uuid', $uuid)->firstOrFail();
    }

    public function cases(Request $r)
    {
        $q = $this->scopedCases($r)->when($r->query('kind'), fn ($q, $k) => $q->where('kind', $k))->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $q->paginate(min(100, max(5, (int) $r->query('per_page', 25))))->through(fn ($c) => $this->cases->presentCase($c));
    }

    public function createCase(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'kind' => ['required', Rule::in(['onboarding', 'offboarding'])], 'anchor_date' => 'nullable|date_format:Y-m-d']);
        $e = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'lifecycle.manage')->where('uuid', $d['employee_uuid'])->firstOrFail();
        $anchor = $d['anchor_date'] ?? ($d['kind'] === 'onboarding' ? $e->joined_on->toDateString() : ($e->separated_on?->toDateString()));
        abort_unless($anchor, 422, 'Give the anchor date (the last working date is not set for this employee).');
        $c = $this->cases->generate($d['kind'], $e, $anchor, ['source' => 'manual']);

        return response()->json($this->cases->presentCase($c, true), 201);
    }

    public function showCase(Request $r, string $uuid)
    {
        return $this->cases->presentCase($this->case($r, $uuid), true);
    }

    public function addTask(Request $r, string $uuid)
    {
        $c = $this->case($r, $uuid, 'lifecycle.manage');
        $d = $r->validate(['title' => 'required|string|max:200', 'group_name' => 'nullable|string|max:80', 'description' => 'nullable|string|max:2000', 'owner_type' => ['required', Rule::in(TemplateService::OWNERS)],
            'owner_user_id' => 'nullable|integer', 'due_date' => 'nullable|date_format:Y-m-d', 'mandatory' => 'nullable|boolean', 'clearance_dept' => 'nullable|string|max:40', 'requires_document' => 'nullable|boolean']);

        return response()->json($this->cases->presentTask($this->cases->addTask($r->user(), $c, $d)), 201);
    }

    public function reschedule(Request $r, string $uuid)
    {
        $d = $r->validate(['anchor_date' => 'required|date_format:Y-m-d']);

        return $this->cases->presentCase($this->cases->reschedule($r->user(), $this->case($r, $uuid, 'lifecycle.manage'), $d['anchor_date']), true);
    }

    public function cancelCase(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return $this->cases->presentCase($this->cases->cancel($this->case($r, $uuid, 'lifecycle.manage'), $d['reason']), true);
    }

    public function myTasks(Request $r)
    {
        return LifecycleTask::where('owner_user_id', $r->user()->id)->where('status', 'pending')->whereIn('case_id', LifecycleCase::where('status', 'open')->select('id'))->orderBy('due_date')->get()->map(fn ($t) => $this->cases->presentTask($t));
    }

    public function completeTask(Request $r, string $uuid)
    {
        $d = $r->validate(['note' => 'nullable|string|max:2000', 'document_ref' => 'nullable|string|max:160', 'outcome' => ['nullable', Rule::in(['done', 'skipped'])]]);
        $task = LifecycleTask::where('uuid', $uuid)->firstOrFail();
        $case = LifecycleCase::findOrFail($task->case_id);
        $this->cases->authorizeTask($r->user(), $task, $case); // 404 for strangers (no existence leak)

        return $this->cases->presentTask($this->cases->complete($r->user(), $task, $d));
    }

    // ---- dashboard ------------------------------------------------------------------------------------------------------------------

    public function dashboard(Request $r)
    {
        $today = TenantClock::today();
        $scope = app(DataScope::class);
        $open = $this->scopedCases($r)->where('status', 'open');
        $caseIds = (clone $open)->pluck('id');
        $tasks = LifecycleTask::whereIn('case_id', $caseIds)->where('status', 'pending');
        $overdue = (clone $tasks)->where('due_date', '<', $today);
        $owners = User::whereIn('id', (clone $overdue)->pluck('owner_user_id')->filter())->pluck('name', 'id');
        $byOwner = (clone $overdue)->get()->groupBy(fn ($t) => $t->owner_user_id ?: 0)->map(fn ($g, $id) => ['owner' => $id ? ($owners[$id] ?? 'Unknown') : 'Unassigned', 'overdue' => $g->count()])->sortByDesc('overdue')->values()->all();
        $joining = (clone $open)->where('kind', 'onboarding')->whereBetween('anchor_date', [$today, now()->parse($today)->addDays(14)->toDateString()])->get()->map(fn ($c) => $this->cases->presentCase($c));

        return [
            'open_onboarding' => (clone $open)->where('kind', 'onboarding')->count(), 'open_offboarding' => (clone $open)->where('kind', 'offboarding')->count(),
            'tasks_pending' => (clone $tasks)->count(), 'tasks_overdue' => (clone $overdue)->count(), 'blockers_by_owner' => $byOwner,
            'joining_soon' => $joining->map(fn ($c) => $c + [])->all(), 'joining_not_ready' => $joining->where('readiness.day_one_ready', false)->values()->all(),
            'pending_moves' => Move::where('status', 'pending')->whereIn('employee_id', $scope->constrain(Employee::query(), $r->user(), 'lifecycle.view')->select('employees.id'))->count(),
        ];
    }

    public function preboarders(Request $r)
    {
        $emps = app(DataScope::class)->constrain(Employee::query()->where('status', 'preboarding')->with('person'), $r->user(), 'lifecycle.view')->orderBy('joined_on')->get();

        return $emps->map(function ($e) {
            $c = LifecycleCase::where('employee_id', $e->id)->where('kind', 'onboarding')->where('status', 'open')->first();

            return ['employee_uuid' => $e->uuid, 'name' => $e->person?->display_name, 'joining_date' => $e->joined_on->toDateString(), 'case' => $c ? $this->cases->presentCase($c) : null];
        });
    }

    // ---- moves ----------------------------------------------------------------------------------------------------------------------

    public function moves(Request $r)
    {
        $permission = $r->user()->allows('lifecycle.moves.manage') ? 'lifecycle.moves.manage' : 'lifecycle.moves.approve';
        $q = Move::whereIn('employee_id', app(DataScope::class)->constrain(Employee::query(), $r->user(), $permission)->select('employees.id'))->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $q->paginate(25)->through(fn ($m) => $this->moves->present($m, $r->user()));
    }

    public function requestMove(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'kind' => ['required', Rule::in(MoveService::KINDS)], 'changes' => 'required|array', 'effective_date' => 'required|date_format:Y-m-d', 'reason' => 'nullable|string|max:1000']);
        $e = Employee::where('uuid', $d['employee_uuid'])->firstOrFail();
        $m = $this->moves->request($r->user(), $e, $d);

        return response()->json($this->moves->present($m, $r->user()), 201);
    }

    public function decideMove(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:1000']);

        return $this->moves->present($this->moves->decide($r->user(), Move::where('uuid', $uuid)->firstOrFail(), $d['decision'], $d['note'] ?? null), $r->user());
    }

    public function cancelMove(Request $r, string $uuid)
    {
        return $this->moves->present($this->moves->cancel($r->user(), Move::where('uuid', $uuid)->firstOrFail()), $r->user());
    }

    // ---- offboarding ----------------------------------------------------------------------------------------------------------------

    public function clearance(Request $r, string $uuid)
    {
        return $this->off->clearance($this->case($r, $uuid));
    }

    public function settlement(Request $r, string $uuid)
    {
        $d = $r->validate(['currency' => 'required|string|size:3', 'daily_rate' => 'nullable|string', 'salary_days' => 'nullable|string']);
        $c = $this->off->initiateSettlement($r->user(), $this->case($r, $uuid, 'lifecycle.clearance.manage'), $d);

        return ['case' => $c->uuid, 'settlement_uuid' => $c->settlement_uuid];
    }

    public function exitInterview(Request $r, string $uuid)
    {
        return $this->off->readInterview($r->user(), $this->case($r, $uuid, 'lifecycle.exit.view'));
    }

    private function ownOffboarding(Request $r): LifecycleCase
    {
        return LifecycleCase::whereIn('employee_id', app(DataScope::class)->ownEmployeeIds($r->user()))->where('kind', 'offboarding')->where('status', 'open')->orderByDesc('id')->firstOrFail();
    }

    public function myExitInterview(Request $r)
    {
        $c = $this->ownOffboarding($r);

        return ['case' => $c->uuid, 'status' => ExitInterview::where('case_id', $c->id)->value('status')];
    }

    public function submitMyExitInterview(Request $r)
    {
        $d = $r->validate(['answers' => 'required|array|min:1|max:50', 'answers.*' => 'nullable|string|max:5000']);
        $i = $this->off->submitInterview($r->user(), $this->ownOffboarding($r), $d['answers']);

        return ['status' => $i->status, 'submitted_at' => $i->submitted_at?->toIso8601String()];
    }

    // ---- alumni ---------------------------------------------------------------------------------------------------------------------

    public function alumni(Request $r)
    {
        $q = Alumnus::whereIn('employee_id', app(DataScope::class)->constrain(Employee::query(), $r->user(), 'lifecycle.alumni.manage')->select('employees.id'))->orderByDesc('id');

        return $q->paginate(25)->through(function ($a) {
            $p = Employee::with('person')->find($a->employee_id);

            return ['uuid' => $a->uuid, 'employee_uuid' => $p->uuid, 'name' => $p->person?->display_name, 'last_working_date' => $a->last_working_date?->toDateString(), 'separation_type' => $a->separation_type,
                'rehire_eligible' => $a->rehire_eligible, 'rehire_note' => $a->rehire_note];
        });
    }

    public function setEligibility(Request $r, string $uuid)
    {
        $d = $r->validate(['rehire_eligible' => 'present|nullable|boolean', 'note' => 'nullable|string|max:1000']);
        $a = Alumnus::whereIn('employee_id', app(DataScope::class)->constrain(Employee::query(), $r->user(), 'lifecycle.alumni.manage')->select('employees.id'))->where('uuid', $uuid)->firstOrFail();
        $a = $this->off->setEligibility($r->user(), $a, $d['rehire_eligible'], $d['note'] ?? null);

        return ['uuid' => $a->uuid, 'rehire_eligible' => $a->rehire_eligible, 'rehire_note' => $a->rehire_note];
    }
}
