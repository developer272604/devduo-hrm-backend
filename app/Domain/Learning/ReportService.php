<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Assignment, Budget, Course, EmployeeCert, Enrolment, Session};
use App\Domain\Learning\Support\Money;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Manager team dashboard, L&D command center and masked export. Money is summed with decimal arithmetic per currency; cost is shown only to viewers with learning.reports.view. */
final class ReportService
{
    public function __construct(private LearningAccess $access, private ComplianceService $compliance, private SkillService $skills) {}

    private function permission(User $u): string
    {
        return $this->access->heldPermission($u, ['learning.reports.view', 'learning.view', 'learning.enrol.manage', 'learning.assign.manage']);
    }

    /** @return array<string,string> currency => total */
    public function costByCurrency(iterable $rows): array
    {
        $by = [];
        foreach ($rows as $r) $by[$r->currency][] = $r->cost;

        return collect($by)->map(fn ($v) => Money::sum($v))->sortKeys()->all();
    }

    public function dashboard(User $u): array
    {
        $perm = $this->permission($u); $today = TenantClock::today();
        $real = $this->access->reachIds($u, $perm)->pluck('id')->all(); $ids = $real ?: [0];
        $enr = Enrolment::whereIn('employee_id', $ids)->get(['id', 'status', 'cost', 'currency', 'completed_at', 'course_id', 'employee_id', 'due_on']);
        $monthStart = CarbonImmutable::parse($today)->startOfMonth();
        $showCost = $u->allows('learning.reports.view');
        $spend = $enr->whereIn('status', ['enrolled', 'in_progress', 'completed']);
        $assignOpen = Assignment::whereIn('employee_id', $ids)->where('status', 'assigned')->get();
        $status = $this->compliance->status($u, $perm);
        $soon = CarbonImmutable::parse($today)->addDays(60)->toDateString();

        return ['scope' => $this->access->tenantWide($u, $perm) ? 'tenant' : 'team', 'people' => count($real),
            'enrolments' => $enr->groupBy('status')->map->count()->all(), 'completions_this_month' => $enr->where('status', 'completed')->filter(fn ($e) => $e->completed_at && $e->completed_at >= $monthStart)->count(),
            'pending_approvals' => $enr->where('status', 'pending_approval')->count(),
            'overdue_learning' => $enr->whereIn('status', ['enrolled', 'in_progress'])->filter(fn ($e) => $e->due_on && $e->due_on->format('Y-m-d') < $today)->count(),
            'compliance' => $status['summary'] + ['open_assignments' => $assignOpen->count(), 'overdue_assignments' => $assignOpen->filter(fn ($a) => $a->due_on->format('Y-m-d') < $today)->count()],
            'certificates_expiring' => EmployeeCert::whereIn('employee_id', $ids)->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<=', $soon)->count(),
            'skill_gaps' => array_slice($this->skills->teamGaps($u, $perm), 0, 5),
            'cost' => $showCost ? $this->costByCurrency($spend) : null, 'cost_masked' => ! $showCost];
    }

    /** L&D command center: tenant-wide dashboard plus catalog health, sessions, popular courses, budgets. */
    public function commandCenter(User $u): array
    {
        abort_unless($u->allows('learning.reports.view') && $this->access->tenantWide($u, 'learning.reports.view'), 403, 'The command center needs organization-wide learning reporting access.');
        $today = TenantClock::today(); $base = $this->dashboard($u);
        $courses = Course::get(['id', 'uuid', 'code', 'title', 'status']);
        $counts = Enrolment::whereIn('status', ['enrolled', 'in_progress', 'completed', 'waitlisted', 'pending_approval'])->selectRaw('course_id, count(*) c')->groupBy('course_id')->pluck('c', 'course_id');
        $popular = $courses->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'title' => $c->title, 'enrolments' => (int) ($counts[$c->id] ?? 0)])->sortByDesc('enrolments')->take(5)->values()->all();
        $sessions = Session::where('status', 'scheduled')->where('starts_on', '>=', $today)->orderBy('starts_on')->limit(8)->get();
        $filled = Enrolment::whereIn('session_id', $sessions->pluck('id'))->whereIn('status', ['enrolled', 'in_progress'])->selectRaw('session_id, count(*) c')->groupBy('session_id')->pluck('c', 'session_id');
        $spent = $this->costByCurrency(Enrolment::whereIn('status', ['enrolled', 'in_progress', 'completed'])->get(['cost', 'currency']));
        $budgets = Budget::where('active', true)->get()->map(fn ($b) => ['uuid' => $b->uuid, 'name' => $b->name, 'fiscal_year' => $b->fiscal_year, 'amount' => Money::in($b->amount), 'currency' => $b->currency,
            'spent' => $spent[$b->currency] ?? '0.00'])->all();

        return $base + ['catalog' => ['courses' => $courses->count(), 'published' => $courses->where('status', 'published')->count(), 'draft' => $courses->where('status', 'draft')->count(), 'retired' => $courses->where('status', 'retired')->count()],
            'popular_courses' => $popular, 'upcoming_sessions' => $sessions->map(fn ($s) => ['uuid' => $s->uuid, 'code' => $s->code, 'starts_on' => $s->starts_on->format('Y-m-d'), 'capacity' => $s->capacity, 'filled' => (int) ($filled[$s->id] ?? 0)])->all(),
            'budgets' => $budgets];
    }

    /** CSV rows for the export. Cost is masked unless the exporter is tenant-wide; names/numbers are limited to people in scope. Cells that could be read as formulas are neutralised. */
    public function exportRows(User $u): array
    {
        abort_unless($u->allows('learning.reports.export'), 403);
        $ids = $this->access->reachIds($u, 'learning.reports.export')->pluck('id')->all() ?: [0];
        $full = $this->access->tenantWide($u, 'learning.reports.export');
        $enr = Enrolment::whereIn('employee_id', $ids)->orderBy('id')->get();
        $people = $this->access->people($enr->pluck('employee_id')); $courses = Course::whereIn('id', $enr->pluck('course_id'))->get()->keyBy('id');
        $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
        $rows = [['Employee number', 'Employee', 'Course code', 'Course', 'Status', 'Source', 'Due on', 'Completed at', 'Score', 'Cost', 'Currency']];
        foreach ($enr as $e) {
            $rows[] = array_map($safe, [$people[$e->employee_id]['number'] ?? '', $people[$e->employee_id]['name'] ?? '', $courses[$e->course_id]->code ?? '', $courses[$e->course_id]->title ?? '', $e->status, $e->source, $e->due_on?->format('Y-m-d') ?? '',
                $e->completed_at?->format('Y-m-d') ?? '', $e->score ?? '', $full ? Money::in($e->cost) : '***', $e->currency]);
        }
        Audit::record('report.exported', 'learning', 'enrolments', ['rows' => count($rows) - 1, 'cost_masked' => ! $full]);

        return $rows;
    }
}
