<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Assessment, Certification, Course, CoursePrerequisite, CourseSkill, CourseVersion, Enrolment, Path, PathItem, Session, Skill};
use App\Domain\Learning\Support\Money;
use App\Domain\Notifications\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Course catalog (internal/external/online/classroom), versions, prerequisites, skills granted, learning paths, sessions/batches and assessments. */
final class CatalogService
{
    public const DELIVERY = ['internal', 'external', 'online', 'classroom'];
    private const MATERIAL = ['title', 'description', 'duration_hours', 'cost', 'currency', 'pass_percent', 'min_attendance_pct', 'delivery'];

    public function __construct(private LearningAccess $access) {}

    public function present(Course $c, bool $detail = false): array
    {
        $row = ['uuid' => $c->uuid, 'code' => $c->code, 'title' => $c->title, 'description' => $c->description, 'delivery' => $c->delivery, 'provider' => $c->provider, 'status' => $c->status, 'duration_hours' => $c->duration_hours,
            'cost' => Money::in($c->cost), 'currency' => $c->currency, 'capacity' => $c->capacity, 'requires_approval' => (bool) $c->requires_approval, 'version' => $c->version];
        if ($detail) {
            $row += ['pass_percent' => $c->pass_percent, 'min_attendance_pct' => $c->min_attendance_pct, 'provider_ref' => $c->provider_ref,
                'certification' => $c->certification_id ? Certification::whereKey($c->certification_id)->value('code') : null,
                'prerequisites' => Course::whereIn('id', CoursePrerequisite::where('course_id', $c->id)->select('prerequisite_id'))->get()->map(fn ($p) => ['uuid' => $p->uuid, 'code' => $p->code, 'title' => $p->title])->all(),
                'skills' => CourseSkill::where('course_id', $c->id)->get()->map(fn ($s) => ['skill_uuid' => Skill::whereKey($s->skill_id)->value('uuid'), 'skill' => Skill::whereKey($s->skill_id)->value('name'), 'grants_rank' => (int) $s->grants_rank])->all(),
                'versions' => CourseVersion::where('course_id', $c->id)->orderBy('version')->pluck('version')->all()];
        }

        return $row;
    }

    private function snapshot(Course $c, User $actor): void
    {
        CourseVersion::create(['course_id' => $c->id, 'version' => $c->version, 'snapshot' => $c->only(self::MATERIAL), 'created_by' => $actor->id]);
    }

    public function create(User $actor, array $d): Course
    {
        if (Course::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A course with this code already exists.']);

        return DB::transaction(function () use ($actor, $d) {
            $c = Course::create($this->fields($d) + ['status' => 'draft', 'version' => 1, 'created_by' => $actor->id]);
            $this->snapshot($c, $actor);
            $this->syncRelations($c, $d);
            Audit::record('course.created', 'learning', 'course:'.$c->uuid);

            return $c;
        });
    }

    public function update(User $actor, Course $c, array $d): Course
    {
        if ($c->status === 'retired') throw ValidationException::withMessages(['status' => 'A retired course cannot be changed.']);

        return DB::transaction(function () use ($actor, $c, $d) {
            $c->fill($this->fields($d));
            $material = $c->isDirty(self::MATERIAL);
            if ($material) $c->version++;
            $c->save();
            if ($material) $this->snapshot($c, $actor);
            $this->syncRelations($c, $d);
            Audit::record('course.updated', 'learning', 'course:'.$c->uuid, ['version' => $c->version]);

            return $c->refresh();
        });
    }

    private function fields(array $d): array
    {
        $out = array_intersect_key($d, array_flip(['code', 'title', 'description', 'delivery', 'provider', 'provider_ref', 'duration_hours', 'capacity', 'requires_approval', 'pass_percent', 'min_attendance_pct', 'currency']));
        if (array_key_exists('cost', $d)) $out['cost'] = Money::in($d['cost']);
        if (! empty($d['currency'])) $out['currency'] = strtoupper($d['currency']);
        if (! empty($d['certification_code'])) $out['certification_id'] = Certification::where('code', $d['certification_code'])->firstOrFail()->id;
        if (array_key_exists('certification_code', $d) && empty($d['certification_code'])) $out['certification_id'] = null;

        return $out;
    }

    private function syncRelations(Course $c, array $d): void
    {
        if (isset($d['skills'])) {
            $max = app(SkillService::class)->maxRank();
            CourseSkill::where('course_id', $c->id)->delete();
            foreach ($d['skills'] as $s) {
                if ($s['grants_rank'] < 1 || $s['grants_rank'] > $max) throw ValidationException::withMessages(['skills' => 'Granted level must be between 1 and '.$max.'.']);
                CourseSkill::create(['course_id' => $c->id, 'skill_id' => Skill::where('uuid', $s['skill_uuid'])->firstOrFail()->id, 'grants_rank' => $s['grants_rank']]);
            }
        }
        if (isset($d['prerequisite_uuids'])) {
            CoursePrerequisite::where('course_id', $c->id)->delete();
            foreach (array_unique($d['prerequisite_uuids']) as $uuid) {
                $p = Course::where('uuid', $uuid)->firstOrFail();
                if ($p->id === $c->id || $this->reaches($p->id, $c->id)) throw ValidationException::withMessages(['prerequisite_uuids' => 'A course cannot require itself, directly or through a chain.']);
                CoursePrerequisite::create(['course_id' => $c->id, 'prerequisite_id' => $p->id]);
            }
        }
    }

    /** True when $from (transitively) requires $target. */
    private function reaches(int $from, int $target, int $depth = 0): bool
    {
        if ($depth > 20) return true;
        foreach (CoursePrerequisite::where('course_id', $from)->pluck('prerequisite_id') as $id) if ($id === $target || $this->reaches($id, $target, $depth + 1)) return true;

        return false;
    }

    public function setStatus(User $actor, Course $c, string $status): Course
    {
        if ($status === 'published' && $c->status === 'retired') throw ValidationException::withMessages(['status' => 'A retired course cannot be published again.']);
        $c->update(['status' => $status]);
        Audit::record('course.'.$status, 'learning', 'course:'.$c->uuid);

        return $c;
    }

    public function createPath(User $actor, array $d): Path
    {
        return DB::transaction(function () use ($actor, $d) {
            $p = Path::create(['name' => $d['name'], 'description' => $d['description'] ?? null, 'status' => $d['status'] ?? 'draft', 'created_by' => $actor->id]);
            foreach (array_values($d['items']) as $i => $it) PathItem::create(['path_id' => $p->id, 'course_id' => Course::where('uuid', $it['course_uuid'])->firstOrFail()->id, 'position' => $i + 1, 'required' => $it['required'] ?? true]);
            Audit::record('path.created', 'learning', 'path:'.$p->uuid);

            return $p;
        });
    }

    public function presentPath(Path $p, ?int $employeeId = null): array
    {
        $items = PathItem::where('path_id', $p->id)->orderBy('position')->get();
        $courses = Course::whereIn('id', $items->pluck('course_id'))->get()->keyBy('id');
        $done = $employeeId ? Enrolment::where('employee_id', $employeeId)->where('status', 'completed')->pluck('course_id')->all() : [];
        $rows = $items->map(fn ($i) => ['position' => $i->position, 'required' => (bool) $i->required, 'course' => ['uuid' => $courses[$i->course_id]->uuid, 'code' => $courses[$i->course_id]->code, 'title' => $courses[$i->course_id]->title],
            'completed' => in_array($i->course_id, $done, true)])->all();
        $req = array_filter($rows, fn ($r) => $r['required']);

        return ['uuid' => $p->uuid, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status, 'items' => $rows,
            'progress' => $employeeId ? ['required' => count($req), 'completed' => count(array_filter($req, fn ($r) => $r['completed']))] : null];
    }

    public function createSession(User $actor, Course $c, array $d): Session
    {
        if ($c->status !== 'published') throw ValidationException::withMessages(['course' => 'Publish the course before scheduling sessions.']);
        if (Session::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A session with this code already exists.']);
        $s = Session::create(['course_id' => $c->id, 'code' => $d['code'], 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'location' => $d['location'] ?? null, 'instructor' => $d['instructor'] ?? null,
            'capacity' => $d['capacity'] ?? $c->capacity, 'waitlist_enabled' => $d['waitlist_enabled'] ?? true, 'status' => 'scheduled']);
        Audit::record('session.created', 'learning', 'session:'.$s->uuid);

        return $s;
    }

    public function cancelSession(User $actor, Session $s): Session
    {
        if ($s->status !== 'scheduled') throw ValidationException::withMessages(['status' => 'Only scheduled sessions can be cancelled.']);
        DB::transaction(function () use ($s) {
            $s->update(['status' => 'cancelled']);
            foreach (Enrolment::where('session_id', $s->id)->whereIn('status', ['pending_approval', 'enrolled', 'waitlisted', 'in_progress'])->get() as $e) {
                $e->update(['status' => 'cancelled', 'decision_note' => 'The session was cancelled.']);
                if ($uid = $this->access->userIdOf($e->employee_id)) app(NotificationService::class)->send($uid, 'Session cancelled', 'Session '.$s->code.' was cancelled. Choose another session to continue.', 'learning', '/learning/catalog');
            }
        });
        Audit::record('session.cancelled', 'learning', 'session:'.$s->uuid);

        return $s;
    }

    public function createAssessment(User $actor, Course $c, array $d): Assessment
    {
        $qs = [];
        foreach ($d['questions'] as $i => $q) {
            $correct = array_values((array) $q['correct']);
            foreach ($correct as $ix) if (! isset($q['options'][$ix])) throw ValidationException::withMessages(['questions' => 'Question '.($i + 1).' has a correct answer that is not one of its options.']);
            $qs[] = ['id' => 'q'.($i + 1), 'text' => $q['text'], 'options' => array_values($q['options']), 'correct' => $correct, 'points' => $q['points'] ?? 1];
        }
        $a = Assessment::create(['course_id' => $c->id, 'title' => $d['title'], 'pass_percent' => $d['pass_percent'] ?? ($c->pass_percent ?? 70), 'questions' => $qs, 'active' => true, 'max_attempts' => array_key_exists('max_attempts', $d) ? $d['max_attempts'] : 3]);
        Audit::record('assessment.created', 'learning', 'course:'.$c->uuid, ['assessment' => $a->uuid]);

        return $a;
    }
}
