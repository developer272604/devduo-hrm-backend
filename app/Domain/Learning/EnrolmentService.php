<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Assignment, Attendance, Course, CourseSkill, CoursePrerequisite, Enrolment, Path, PathItem, Session, Skill};
use App\Domain\Learning\Support\Money;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manager/HR assignment of courses and paths, enrolment decisions (maker-checker through the shared approver), session attendance and completion. Capacity/waitlist rules match self-enrolment. */
final class EnrolmentService
{
    public const ACTIVE = ['pending_approval', 'enrolled', 'waitlisted', 'in_progress'];

    public function __construct(private LearningAccess $access, private SkillService $skills, private CertificateService $certs) {}

    public function present(Enrolment $e, array $people = [], ?Course $course = null): array
    {
        $course ??= Course::find($e->course_id);
        $who = $people[$e->employee_id] ?? ($this->access->people([$e->employee_id])[$e->employee_id] ?? null);

        return ['uuid' => $e->uuid, 'status' => $e->status, 'source' => $e->source, 'due_on' => $e->due_on?->format('Y-m-d'), 'score' => $e->score, 'attendance_pct' => $e->attendance_pct,
            'completed_at' => $e->completed_at?->toIso8601String(), 'decision_note' => $e->decision_note, 'decided_at' => $e->decided_at?->toIso8601String(),
            'employee' => $who, 'course' => $course ? ['uuid' => $course->uuid, 'code' => $course->code, 'title' => $course->title] : null,
            'session' => $e->session_id ? Session::whereKey($e->session_id)->first(['uuid', 'code', 'starts_on', 'ends_on']) : null];
    }

    /** Employee ids an assignment targets: explicit employees (all must be reachable, else 404) or the reachable members of a group. @return array<int,int> */
    public function targets(User $actor, array $employeeUuids, ?int $groupId): array
    {
        $ids = [];
        foreach (array_unique($employeeUuids) as $uuid) {
            $emp = $this->access->employeeByUuid($uuid);
            abort_unless($this->access->reaches($actor, $emp, 'learning.enrol.manage'), 404);
            $ids[] = $emp->id;
        }
        if ($groupId) {
            $gid = DB::table('employee_groups')->where('tenant_id', $actor->tenant_id)->where('id', $groupId)->value('id') ?? abort(404);
            $members = DB::table('employee_group_members')->where('tenant_id', $actor->tenant_id)->where('group_id', $gid)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', TenantClock::today()))->pluck('employee_id')->all();
            $ids = array_merge($ids, $this->access->reachIds($actor, 'learning.enrol.manage')->whereIn('employees.id', $members ?: [0])->whereNotIn('employees.status', ['separated', 'inactive', 'archived'])->pluck('id')->map(fn ($i) => (int) $i)->all());
        }
        $ids = array_values(array_unique($ids));
        if (! $ids) throw ValidationException::withMessages(['employee_uuids' => 'Choose at least one employee or a group with members in your scope.']);

        return $ids;
    }

    /** Assign a course. Returns ['assigned'=>[enrolment uuid..], 'skipped'=>[{employee_uuid, reason}]]. */
    public function assignCourse(User $actor, Course $course, array $employeeIds, ?string $dueOn, ?Session $session = null, string $source = 'assigned'): array
    {
        if ($course->status !== 'published') throw ValidationException::withMessages(['course' => 'Only published courses can be assigned.']);
        if ($dueOn && $dueOn < TenantClock::today()) throw ValidationException::withMessages(['due_on' => 'The due date cannot be in the past.']);
        $out = ['assigned' => [], 'skipped' => []];
        $people = $this->access->people($employeeIds);
        foreach ($employeeIds as $id) {
            $reason = null; $enrolment = null;
            DB::transaction(function () use ($actor, $course, $id, $dueOn, $session, $source, &$reason, &$enrolment) {
                $locked = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
                if (Enrolment::where('employee_id', $id)->where('course_id', $course->id)->whereIn('status', [...self::ACTIVE, 'completed'])->exists()) { $reason = 'Already enrolled or completed.'; return; }
                $required = CoursePrerequisite::where('course_id', $course->id)->pluck('prerequisite_id');
                $done = Enrolment::where('employee_id', $id)->whereIn('course_id', $required)->where('status', 'completed')->pluck('course_id');
                if ($required->diff($done)->isNotEmpty()) { $reason = 'Prerequisites not completed.'; return; }
                $status = 'enrolled'; $waitlisted = null;
                if ($session) {
                    $count = Enrolment::where('session_id', $session->id)->whereIn('status', ['enrolled', 'in_progress'])->lockForUpdate()->count();
                    if ($session->capacity && $count >= $session->capacity) {
                        if (! $session->waitlist_enabled) { $reason = 'Session is full.'; return; }
                        $status = 'waitlisted'; $waitlisted = now();
                    }
                }
                $enrolment = Enrolment::create(['course_id' => $course->id, 'session_id' => $session?->id, 'employee_id' => $id, 'status' => $status, 'source' => $source, 'requested_by' => $actor->id, 'decided_by' => $actor->id, 'decided_at' => now(),
                    'due_on' => $dueOn, 'waitlisted_at' => $waitlisted, 'course_version' => $locked->version, 'cost' => $locked->cost, 'currency' => $locked->currency]);
            });
            if ($enrolment) {
                $out['assigned'][] = $enrolment->uuid;
                if ($uid = $this->access->userIdOf($id)) app(NotificationService::class)->send($uid, 'Learning assigned to you', $course->title.($dueOn ? ' is due '.$dueOn.'.' : ' has been assigned.'), 'learning', '/learning/my');
            } else $out['skipped'][] = ['employee_uuid' => $people[$id]['uuid'] ?? null, 'reason' => $reason];
        }
        Audit::record('enrolment.assigned', 'learning', 'course:'.$course->uuid, ['assigned' => count($out['assigned']), 'skipped' => count($out['skipped']), 'due_on' => $dueOn]);

        return $out;
    }

    public function assignPath(User $actor, Path $path, array $employeeIds, ?string $dueOn): array
    {
        $courses = Course::whereIn('id', PathItem::where('path_id', $path->id)->orderBy('position')->pluck('course_id'))->get();
        $out = ['assigned' => [], 'skipped' => []];
        foreach ($courses as $c) {
            if ($c->status !== 'published') { $out['skipped'][] = ['course_uuid' => $c->uuid, 'reason' => 'Course is not published.']; continue; }
            $r = $this->assignCourse($actor, $c, $employeeIds, $dueOn);
            $out['assigned'] = array_merge($out['assigned'], $r['assigned']);
            $out['skipped'] = array_merge($out['skipped'], array_map(fn ($s) => $s + ['course_uuid' => $c->uuid], $r['skipped']));
        }
        Audit::record('path.assigned', 'learning', 'path:'.$path->uuid, ['assigned' => count($out['assigned'])]);

        return $out;
    }

    /** Called when a self-enrolment needs approval: My Work task + notification for the routed approvers (never the requester). */
    public function requestApproval(Enrolment $e): void
    {
        $emp = Employee::find($e->employee_id); $course = Course::find($e->course_id);
        foreach ($this->access->approvers($emp, 'learning.enrol.approve') as $uid) {
            app(TaskService::class)->createOnce(['title' => 'Approve a course enrolment', 'description' => $this->access->name($emp).' requested "'.$course->title.'".', 'assignee_id' => $uid, 'source_module' => 'learning',
                'source_entity' => 'enrolment:'.$e->uuid, 'action' => '/learning/team', 'status' => 'open', 'priority' => 'normal']);
        }
    }

    public function decide(User $actor, Enrolment $e, string $decision, ?string $note): Enrolment
    {
        $emp = Employee::findOrFail($e->employee_id);
        abort_unless($actor->allows('learning.enrol.approve') && $this->access->reaches($actor, $emp, 'learning.enrol.approve'), 404);
        abort_if($this->access->isSelf($actor, $emp) || (int) $e->requested_by === $actor->id, 403, 'You cannot decide your own enrolment request.');
        abort_unless($this->access->tenantWide($actor, 'learning.enrol.approve') || in_array($actor->id, $this->access->approvers($emp, 'learning.enrol.approve'), true), 403, 'This request is routed to a different approver.');
        if ($decision === 'reject' && mb_strlen(trim((string) $note)) < 5) throw ValidationException::withMessages(['note' => 'Give a reason for rejecting (at least 5 characters).']);

        return DB::transaction(function () use ($actor, $e, $decision, $note) {
            $e = Enrolment::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($e->status !== 'pending_approval') throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            $status = 'rejected';
            if ($decision === 'approve') {
                $status = 'enrolled';
                if ($e->session_id) {
                    $s = Session::whereKey($e->session_id)->lockForUpdate()->first();
                    $count = Enrolment::where('session_id', $s->id)->whereIn('status', ['enrolled', 'in_progress'])->count();
                    if ($s->capacity && $count >= $s->capacity) {
                        if (! $s->waitlist_enabled) throw ValidationException::withMessages(['session' => 'The session is now full.']);
                        $status = 'waitlisted'; $e->waitlisted_at = now();
                    }
                }
            }
            $e->fill(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 500) : null])->save();
            app(TaskService::class)->completeBySource('learning', 'enrolment:'.$e->uuid);
            if ($uid = $this->access->userIdOf($e->employee_id)) app(NotificationService::class)->send($uid, 'Enrolment '.($decision === 'approve' ? 'approved' : 'rejected'), 'Your request for '.Course::whereKey($e->course_id)->value('title').' was '.($decision === 'approve' ? 'approved.' : 'rejected.'), 'learning', '/learning/my');
            Audit::record('enrolment.'.$decision.'d', 'learning', 'enrolment:'.$e->uuid, ['status' => $status]);

            return $e;
        });
    }

    private function manageable(User $actor, Enrolment $e): Employee
    {
        $emp = Employee::findOrFail($e->employee_id);
        abort_unless($actor->allows('learning.enrol.manage') && $this->access->reaches($actor, $emp, 'learning.enrol.manage'), 404);

        return $emp;
    }

    public function markAttendance(User $actor, Enrolment $e, string $date, bool $present): Enrolment
    {
        $this->manageable($actor, $e);
        if (! in_array($e->status, ['enrolled', 'in_progress'], true) || ! $e->session_id) throw ValidationException::withMessages(['enrolment' => 'Attendance applies to enrolled participants of a session.']);
        $s = Session::findOrFail($e->session_id);
        if ($date < $s->starts_on->format('Y-m-d') || $date > $s->ends_on->format('Y-m-d')) throw ValidationException::withMessages(['session_date' => 'The date is outside the session dates.']);
        DB::transaction(function () use ($actor, $e, $s, $date, $present) {
            $row = Attendance::where('enrolment_id', $e->id)->where('session_date', $date)->first();
            $row ? $row->update(['present' => $present, 'marked_by' => $actor->id]) : Attendance::create(['enrolment_id' => $e->id, 'session_date' => $date, 'present' => $present, 'marked_by' => $actor->id]);
            $days = max(1, $s->starts_on->diffInDays($s->ends_on) + 1);
            $e->update(['status' => 'in_progress', 'attendance_pct' => Money::pct(Attendance::where('enrolment_id', $e->id)->where('present', true)->count(), $days)]);
        });
        Audit::record('enrolment.attendance_marked', 'learning', 'enrolment:'.$e->uuid, ['date' => $date, 'present' => $present]);

        return $e->refresh();
    }

    public function complete(User $actor, Enrolment $e, ?string $score, bool $passed = true): Enrolment
    {
        $emp = $this->manageable($actor, $e);
        abort_if($this->access->isSelf($actor, $emp), 403, 'You cannot record your own completion.');
        if (! in_array($e->status, ['enrolled', 'in_progress'], true)) throw ValidationException::withMessages(['status' => 'Only active enrolments can be completed.']);
        $course = Course::findOrFail($e->course_id);
        $outcome = $passed && ($course->pass_percent === null || $score === null || ! Money::gt($course->pass_percent, $score));
        $e = $this->finish($e, $outcome, $score);
        Audit::record('enrolment.'.($outcome ? 'completed' : 'failed'), 'learning', 'enrolment:'.$e->uuid, ['score' => $score]);

        return $e;
    }

    /** Shared completion path (manager marking or a passed assessment): attendance gate, status, assignment sync, skills and certificate. */
    public function finish(Enrolment $e, bool $passed, ?string $score): Enrolment
    {
        $course = Course::findOrFail($e->course_id);
        if ($passed && $course->min_attendance_pct && Money::gt($course->min_attendance_pct, $e->attendance_pct ?? 0)) {
            throw ValidationException::withMessages(['attendance' => 'Minimum attendance of '.$course->min_attendance_pct.'% has not been met.']);
        }

        return DB::transaction(function () use ($e, $passed, $score, $course) {
            $e->update(['status' => $passed ? 'completed' : 'failed', 'score' => $score !== null ? Money::in($score) : $e->score, 'completed_at' => $passed ? now() : null]);
            if (! $passed) return $e;
            $emp = Employee::find($e->employee_id);
            foreach (CourseSkill::where('course_id', $course->id)->get() as $cs) {
                if ($skill = Skill::whereKey($cs->skill_id)->where('active', true)->first()) $this->skills->setLevel(null, $emp, $skill, (int) $cs->grants_rank, 'training', 'Completed '.$course->code, true);
            }
            foreach (Assignment::where('employee_id', $e->employee_id)->where('course_id', $course->id)->where('status', 'assigned')->get() as $a) {
                $a->update(['status' => 'completed', 'completed_at' => now(), 'enrolment_id' => $e->id]);
                app(TaskService::class)->completeBySource('learning', 'assignment:'.$a->uuid);
                app(TaskService::class)->completeBySource('learning', 'assignment-escalation:'.$a->uuid);
            }
            $this->certs->issueForEnrolment($e, $course);

            return $e;
        });
    }
}
