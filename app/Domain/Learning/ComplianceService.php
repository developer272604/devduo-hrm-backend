<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Assignment, Course, Enrolment, Rule};
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\{TenantClock, TenantContext};
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mandatory/compliance training: rules by designation/department/legal entity/country, idempotent assignment generation, overdue My Work escalation and compliance status. */
final class ComplianceService
{
    public function __construct(private LearningAccess $access) {}

    public function presentRule(Rule $r): array
    {
        return ['uuid' => $r->uuid, 'name' => $r->name, 'course' => Course::whereKey($r->course_id)->first(['uuid', 'code', 'title']), 'designation_id' => $r->designation_id, 'department_id' => $r->department_id, 'legal_entity_id' => $r->legal_entity_id,
            'country' => $r->country, 'due_days' => (int) $r->due_days, 'recurrence_months' => $r->recurrence_months, 'escalation_days' => (int) $r->escalation_days, 'active' => (bool) $r->active];
    }

    public function saveRule(User $actor, array $d, ?Rule $rule = null): Rule
    {
        $data = array_intersect_key($d, array_flip(['name', 'designation_id', 'department_id', 'legal_entity_id', 'due_days', 'recurrence_months', 'escalation_days', 'active']));
        if (array_key_exists('country', $d)) $data['country'] = $d['country'] ? strtoupper($d['country']) : null;
        if (isset($d['course_uuid'])) {
            $c = Course::where('uuid', $d['course_uuid'])->firstOrFail();
            if ($c->status === 'retired') throw ValidationException::withMessages(['course_uuid' => 'A retired course cannot be mandatory.']);
            $data['course_id'] = $c->id;
        }
        foreach (['designation_id' => 'designations', 'department_id' => 'departments', 'legal_entity_id' => 'legal_entities'] as $col => $table) {
            if (! empty($d[$col]) && ! DB::table($table)->where('tenant_id', $actor->tenant_id)->where('id', $d[$col])->exists()) throw ValidationException::withMessages([$col => 'Unknown organization record.']);
        }
        $rule = $rule ? tap($rule)->update($data) : Rule::create($data + ['active' => true]);
        Audit::record('compliance_rule.saved', 'learning', 'rule:'.$rule->uuid);

        return $rule;
    }

    /** Active employees the rule targets today. @return array<int,int> */
    public function matching(Rule $r): array
    {
        $today = TenantClock::today();
        $q = DB::table('employees')->where('employees.tenant_id', app(TenantContext::class)->id)->whereNull('employees.deleted_at')->whereNotIn('employees.status', ['separated', 'inactive', 'archived', 'preboarding'])
            ->join('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'employees.id')->where('a.effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
            });
        if ($r->designation_id) $q->where('a.designation_id', $r->designation_id);
        if ($r->department_id) $q->where('a.department_id', $r->department_id);
        if ($r->legal_entity_id) $q->where('a.legal_entity_id', $r->legal_entity_id);
        if ($r->country) $q->where('employees.country', $r->country);

        return $q->distinct()->pluck('employees.id')->map(fn ($i) => (int) $i)->all();
    }

    /** Generate due assignments (and enrolments) for every active rule. Re-running creates nothing new (unique rule+employee+cycle). */
    public function generate(): array
    {
        $today = TenantClock::today(); $out = ['assigned' => 0, 'already_complete' => 0];
        foreach (Rule::where('active', true)->get() as $rule) {
            $course = Course::find($rule->course_id);
            if (! $course || $course->status !== 'published') continue;
            foreach ($this->matching($rule) as $empId) {
                $last = Assignment::where('rule_id', $rule->id)->where('employee_id', $empId)->orderByDesc('id')->first();
                $n = Assignment::where('rule_id', $rule->id)->where('employee_id', $empId)->count();
                if ($last) {
                    if ($last->status === 'assigned' || ! $rule->recurrence_months) continue;
                    $base = $last->completed_at ?? $last->due_on;
                    if (CarbonImmutable::parse($base)->addMonthsNoOverflow($rule->recurrence_months)->toDateString() > $today) continue;
                }
                $key = $n === 0 ? 'once' : 'c'.($n + 1);
                if ($rule->recurrence_months && $n === 0) $key = 'c1';
                $window = $rule->recurrence_months ? CarbonImmutable::parse($today)->subMonthsNoOverflow($rule->recurrence_months)->toDateString() : '1970-01-01';
                $done = Enrolment::where('employee_id', $empId)->where('course_id', $course->id)->where('status', 'completed')->where('completed_at', '>=', $window.' 00:00:00')->orderByDesc('completed_at')->first();
                $due = CarbonImmutable::parse($today)->addDays((int) $rule->due_days)->toDateString();
                $a = Assignment::create(['rule_id' => $rule->id, 'employee_id' => $empId, 'course_id' => $course->id, 'cycle_key' => $key, 'assigned_on' => $today, 'due_on' => $due,
                    'status' => $done ? 'completed' : 'assigned', 'completed_at' => $done?->completed_at, 'enrolment_id' => $done?->id]);
                if ($done) { $out['already_complete']++; continue; }
                $out['assigned']++;
                $active = Enrolment::where('employee_id', $empId)->where('course_id', $course->id)->whereIn('status', EnrolmentService::ACTIVE)->first();
                if ($active) { $a->update(['enrolment_id' => $active->id]); if (! $active->due_on) $active->update(['due_on' => $due]); }
                else {
                    $en = Enrolment::create(['course_id' => $course->id, 'employee_id' => $empId, 'status' => 'enrolled', 'source' => 'compliance', 'due_on' => $due, 'course_version' => $course->version, 'cost' => $course->cost, 'currency' => $course->currency]);
                    $a->update(['enrolment_id' => $en->id]);
                }
                if ($uid = $this->access->userIdOf($empId)) app(NotificationService::class)->send($uid, 'Mandatory training assigned', $course->title.' is due '.$due.'.', 'learning', '/learning/my');
            }
        }
        Audit::record('compliance.generated', 'learning', 'tenant:'.app(TenantContext::class)->id, $out);

        return $out;
    }

    /** Overdue handling: employee notice (once per day) + My Work task, and after escalation_days a task for the manager (or HR). Safe to run repeatedly. */
    public function escalate(): array
    {
        $today = TenantClock::today(); $out = ['overdue' => 0, 'reminded' => 0, 'escalated' => 0];
        foreach (Assignment::where('status', 'assigned')->where('due_on', '<', $today)->orderBy('id')->get() as $a) {
            $out['overdue']++;
            $course = Course::find($a->course_id); $emp = \App\Domain\People\Models\Employee::find($a->employee_id);
            if (! $course || ! $emp || in_array($emp->status, ['separated', 'inactive', 'archived'], true)) continue;
            $uid = $this->access->userIdOf($emp->id);
            $text = $course->title.' was due '.$a->due_on->format('Y-m-d').'.';
            if ($uid) {
                app(TaskService::class)->createOnce(['title' => 'Complete overdue mandatory training', 'description' => $text, 'assignee_id' => $uid, 'source_module' => 'learning', 'source_entity' => 'assignment:'.$a->uuid, 'action' => '/learning/my', 'status' => 'open', 'priority' => 'high']);
                if (! $a->reminded_at || $a->reminded_at->format('Y-m-d') < $today) {
                    app(NotificationService::class)->send($uid, 'Mandatory training is overdue', $text, 'learning', '/learning/my');
                    $a->update(['reminded_at' => now()]); $out['reminded']++;
                }
            }
            $rule = $a->rule_id ? Rule::find($a->rule_id) : null;
            $after = CarbonImmutable::parse($a->due_on)->addDays((int) ($rule?->escalation_days ?? 0))->toDateString();
            if ($rule && $rule->escalation_days > 0 && ! $a->escalated_at && $today >= $after) {
                $managers = array_filter([$this->access->managerUserId($emp)]) ?: $this->access->approvers($emp, 'learning.assign.manage');
                foreach ($managers as $mid) {
                    app(TaskService::class)->createOnce(['title' => 'Overdue mandatory training in your team', 'description' => $this->access->name($emp).': '.$text, 'assignee_id' => (int) $mid, 'source_module' => 'learning', 'source_entity' => 'assignment-escalation:'.$a->uuid,
                        'action' => '/learning/compliance', 'status' => 'open', 'priority' => 'high']);
                    app(NotificationService::class)->send((int) $mid, 'Overdue mandatory training', $this->access->name($emp).': '.$text, 'learning', '/learning/compliance');
                }
                $a->update(['escalated_at' => now()]); $out['escalated']++;
            }
        }

        return $out;
    }

    public function waive(User $actor, Assignment $a, string $reason): Assignment
    {
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['reason' => 'Give a reason for the waiver (at least 5 characters).']);
        if ($a->status !== 'assigned') throw ValidationException::withMessages(['status' => 'Only open assignments can be waived.']);
        $a->update(['status' => 'waived']);
        app(TaskService::class)->completeBySource('learning', 'assignment:'.$a->uuid);
        app(TaskService::class)->completeBySource('learning', 'assignment-escalation:'.$a->uuid);
        Audit::record('assignment.waived', 'learning', 'assignment:'.$a->uuid, ['reason' => mb_substr($reason, 0, 300)]);

        return $a;
    }

    /** Per-employee compliance for employees in the viewer's scope. status: overdue > due_soon (7 days) > compliant. */
    public function status(User $viewer, string $permission, ?string $statusFilter = null): array
    {
        $today = TenantClock::today(); $soon = CarbonImmutable::parse($today)->addDays(7)->toDateString();
        $ids = $this->access->reachIds($viewer, $permission)->pluck('id')->all();
        $rows = Assignment::whereIn('employee_id', $ids ?: [0])->whereIn('status', ['assigned', 'completed'])->get()->groupBy('employee_id');
        $people = $this->access->people($rows->keys());
        $out = [];
        foreach ($rows as $empId => $list) {
            $open = $list->where('status', 'assigned');
            $overdue = $open->filter(fn ($a) => $a->due_on->format('Y-m-d') < $today)->count();
            $dueSoon = $open->filter(fn ($a) => $a->due_on->format('Y-m-d') >= $today && $a->due_on->format('Y-m-d') <= $soon)->count();
            $status = $overdue ? 'overdue' : ($dueSoon ? 'due_soon' : ($open->count() ? 'in_progress' : 'compliant'));
            if ($statusFilter && $statusFilter !== $status) continue;
            $out[] = ['employee' => $people[$empId] ?? null, 'total' => $list->count(), 'completed' => $list->where('status', 'completed')->count(), 'open' => $open->count(), 'overdue' => $overdue, 'due_soon' => $dueSoon,
                'next_due' => $open->min(fn ($a) => $a->due_on->format('Y-m-d')), 'status' => $status];
        }
        usort($out, fn ($a, $b) => [$b['overdue'], $a['employee']['name'] ?? ''] <=> [$a['overdue'], $b['employee']['name'] ?? '']);
        $total = array_sum(array_column($out, 'total')); $done = array_sum(array_column($out, 'completed'));

        return ['summary' => ['employees' => count($out), 'overdue_employees' => count(array_filter($out, fn ($r) => $r['status'] === 'overdue')), 'assignments' => $total, 'completed' => $done,
            'compliance_pct' => \App\Domain\Learning\Support\Money::pct($done, $total)], 'rows' => $out];
    }

    public function presentAssignment(Assignment $a, array $people = []): array
    {
        $c = Course::find($a->course_id); $today = TenantClock::today();

        return ['uuid' => $a->uuid, 'status' => $a->status, 'assigned_on' => $a->assigned_on->format('Y-m-d'), 'due_on' => $a->due_on->format('Y-m-d'), 'overdue' => $a->status === 'assigned' && $a->due_on->format('Y-m-d') < $today,
            'completed_at' => $a->completed_at?->toIso8601String(), 'course' => $c ? ['uuid' => $c->uuid, 'code' => $c->code, 'title' => $c->title] : null, 'employee' => $people[$a->employee_id] ?? null];
    }
}
