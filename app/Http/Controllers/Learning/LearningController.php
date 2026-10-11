<?php

namespace App\Http\Controllers\Learning;

use App\Domain\Learning\{CatalogService, LearningAccess, SkillService};
use App\Domain\Learning\Models\{Course, Enrolment, Path, Session, Skill};
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LearningController
{
    public function __construct(private CatalogService $catalog, private LearningAccess $access, private SkillService $skills) {}

    public function courses(Request $r)
    {
        $manage = $r->user()->allows('learning.catalog.manage');
        $q = Course::query()->when(! $manage, fn ($q) => $q->where('status', 'published'));
        if ($r->filled('search')) $q->where(fn ($q) => $q->where('title', 'like', '%'.$r->query('search').'%')->orWhere('code', 'like', '%'.$r->query('search').'%'));
        if ($r->filled('delivery')) $q->where('delivery', $r->query('delivery'));

        return $q->orderBy('title')->paginate(min(100, max(1, (int) $r->query('per_page', 25))))->through(fn ($c) => $this->catalog->present($c));
    }

    public function course(string $uuid)
    {
        $c = Course::where('uuid', $uuid)->firstOrFail();
        abort_unless($c->status === 'published' || request()->user()->allows('learning.catalog.manage'), 404);

        return $this->catalog->present($c, true);
    }

    public function createCourse(Request $r)
    {
        $d = $r->validate([
            'code' => 'required|string|max:40', 'title' => 'required|string|max:200', 'description' => 'nullable|string|max:10000',
            'delivery' => ['required', Rule::in(CatalogService::DELIVERY)], 'provider' => 'nullable|string|max:120', 'provider_ref' => 'nullable|string|max:120',
            'duration_hours' => 'nullable|numeric|min:0|max:99999', 'cost' => 'nullable|numeric|min:0', 'currency' => 'nullable|string|size:3',
            'capacity' => 'nullable|integer|min:1', 'requires_approval' => 'nullable|boolean', 'pass_percent' => 'nullable|numeric|min:0|max:100',
            'min_attendance_pct' => 'nullable|numeric|min:0|max:100', 'certification_code' => 'nullable|string|max:40',
            'skills' => 'sometimes|array|max:50', 'skills.*.skill_uuid' => 'required_with:skills|uuid', 'skills.*.grants_rank' => 'required_with:skills|integer|min:1|max:10',
            'prerequisite_uuids' => 'sometimes|array|max:50', 'prerequisite_uuids.*' => 'uuid',
        ]);

        return response()->json($this->catalog->present($this->catalog->create($r->user(), $d), true), 201);
    }

    public function updateCourse(Request $r, string $uuid)
    {
        $d = $r->validate([
            'code' => 'sometimes|string|max:40', 'title' => 'sometimes|string|max:200', 'description' => 'nullable|string|max:10000',
            'delivery' => ['sometimes', Rule::in(CatalogService::DELIVERY)], 'provider' => 'nullable|string|max:120', 'provider_ref' => 'nullable|string|max:120',
            'duration_hours' => 'sometimes|numeric|min:0|max:99999', 'cost' => 'sometimes|numeric|min:0', 'currency' => 'sometimes|string|size:3',
            'capacity' => 'nullable|integer|min:1', 'requires_approval' => 'sometimes|boolean', 'pass_percent' => 'nullable|numeric|min:0|max:100',
            'min_attendance_pct' => 'sometimes|numeric|min:0|max:100', 'certification_code' => 'nullable|string|max:40',
            'skills' => 'sometimes|array|max:50', 'skills.*.skill_uuid' => 'required_with:skills|uuid', 'skills.*.grants_rank' => 'required_with:skills|integer|min:1|max:10',
            'prerequisite_uuids' => 'sometimes|array|max:50', 'prerequisite_uuids.*' => 'uuid',
        ]);

        return $this->catalog->present($this->catalog->update($r->user(), Course::where('uuid', $uuid)->firstOrFail(), $d), true);
    }

    public function courseStatus(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => ['required', Rule::in(['published', 'retired'])]]);
        return $this->catalog->present($this->catalog->setStatus($r->user(), Course::where('uuid', $uuid)->firstOrFail(), $d['status']), true);
    }

    public function paths(Request $r)
    {
        $q = Path::query()->where('status', 'published');
        if ($r->user()->allows('learning.catalog.manage')) $q = Path::query();
        $employeeId = $this->access->own($r->user())[0] ?? null;
        return $q->orderBy('name')->get()->map(fn ($p) => $this->catalog->presentPath($p, $employeeId));
    }

    public function createPath(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'description' => 'nullable|string|max:5000', 'items' => 'required|array|min:1|max:100',
            'items.*.course_uuid' => 'required|uuid', 'items.*.required' => 'sometimes|boolean']);
        return response()->json($this->catalog->presentPath($this->catalog->createPath($r->user(), $d)), 201);
    }

    public function sessions(Request $r, string $uuid)
    {
        $c = Course::where('uuid', $uuid)->where('status', 'published')->firstOrFail();
        return Session::where('course_id', $c->id)->where('status', 'scheduled')->orderBy('starts_on')->get();
    }

    public function createSession(Request $r, string $uuid)
    {
        $d = $r->validate(['code' => 'required|string|max:40', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'location' => 'nullable|string|max:160', 'instructor' => 'nullable|string|max:120', 'capacity' => 'nullable|integer|min:1', 'waitlist_enabled' => 'sometimes|boolean']);
        return response()->json($this->catalog->createSession($r->user(), Course::where('uuid', $uuid)->firstOrFail(), $d), 201);
    }

    public function myLearning(Request $r)
    {
        $ids = $this->access->own($r->user());
        return Enrolment::whereIn('employee_id', $ids ?: [0])->with('course')->latest('id')->get()->map(fn ($e) => [
            'uuid' => $e->uuid, 'status' => $e->status, 'due_on' => $e->due_on?->format('Y-m-d'), 'completed_at' => $e->completed_at?->toIso8601String(),
            'course' => $this->catalog->present($e->course),
        ]);
    }

    public function enrol(Request $r, string $uuid)
    {
        $d = $r->validate(['session_uuid' => 'nullable|uuid']);
        $employeeId = $this->access->own($r->user())[0] ?? abort(403, 'No employee profile is linked to this account.');
        $course = Course::where('uuid', $uuid)->where('status', 'published')->firstOrFail();
        $session = ! empty($d['session_uuid']) ? Session::where('uuid', $d['session_uuid'])->where('course_id', $course->id)->where('status', 'scheduled')->firstOrFail() : null;

        $enrolment = DB::transaction(function () use ($r, $course, $session, $employeeId) {
            $lockedCourse = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            if (Enrolment::where('employee_id', $employeeId)->where('course_id', $course->id)->whereIn('status', ['pending_approval', 'enrolled', 'waitlisted', 'in_progress', 'completed'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['course' => 'You already have an active or completed enrolment for this course.']);
            }
            $required = DB::table('learning_course_prerequisites')->where('course_id', $course->id)->pluck('prerequisite_id');
            $done = Enrolment::where('employee_id', $employeeId)->whereIn('course_id', $required)->where('status', 'completed')->pluck('course_id');
            if ($required->diff($done)->isNotEmpty()) throw ValidationException::withMessages(['course' => 'Complete the course prerequisites before enrolling.']);
            $count = $session ? Enrolment::where('session_id', $session->id)->whereIn('status', ['enrolled', 'in_progress'])->lockForUpdate()->count() : 0;
            $full = $session && $session->capacity && $count >= $session->capacity;
            if ($full && ! $session->waitlist_enabled) throw ValidationException::withMessages(['session_uuid' => 'This session is full.']);
            $status = $full ? 'waitlisted' : ($course->requires_approval ? 'pending_approval' : 'enrolled');
            return Enrolment::create(['course_id' => $course->id, 'session_id' => $session?->id, 'employee_id' => $employeeId, 'status' => $status,
                'source' => 'self', 'requested_by' => $r->user()->id, 'waitlisted_at' => $full ? now() : null, 'course_version' => $lockedCourse->version, 'cost' => $lockedCourse->cost, 'currency' => $lockedCourse->currency]);
        });
        return response()->json(['uuid' => $enrolment->uuid, 'status' => $enrolment->status], 201);
    }

    public function skills(Request $r)
    {
        $this->skills->ensureUuids();
        return Skill::where('active', true)->orderBy('name')->get()->map(fn ($s) => ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description]);
    }

    public function levels() { return $this->skills->levels()->map(fn ($l) => ['rank' => $l->rank, 'name' => $l->name, 'description' => $l->description]); }

    public function mySkills(Request $r)
    {
        $emp = Employee::whereIn('id', $this->access->own($r->user()) ?: [0])->firstOrFail();
        return ['profile' => $this->skills->profile($emp, true), 'gap' => $this->skills->gap($emp)];
    }

    public function declareSkill(Request $r)
    {
        $d = $r->validate(['skill_uuid' => 'required|uuid', 'level' => 'required|integer|min:1|max:10', 'note' => 'nullable|string|max:300']);
        $emp = Employee::whereIn('id', $this->access->own($r->user()) ?: [0])->firstOrFail();
        $skill = Skill::where('uuid', $d['skill_uuid'])->where('active', true)->firstOrFail();
        $this->skills->setLevel($r->user(), $emp, $skill, $d['level'], 'self', $d['note'] ?? null);
        return $this->skills->profile($emp, true);
    }
}
