<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeCertification;
use App\Domain\People\Models\EmployeeSkill;
use App\Domain\People\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catalog skills assigned to a person: proficiency, experience, last used, optional certification link.
 * Unique per person + skill. Skills are addressed by the catalog skill id (the catalog is tenant reference data).
 * Deliberately no skill intelligence (matching, scoring, gaps): this is the foundation for later modules.
 */
class EmployeeSkillService
{
    public const PROFICIENCIES = ['beginner', 'intermediate', 'advanced', 'expert'];

    public function __construct(private DomainEvents $events) {}

    /** @return Collection<int,EmployeeSkill> */
    public function list(Employee $employee): Collection
    {
        return EmployeeSkill::query()->where('employee_skills.person_id', $employee->person_id)->with(['skill', 'certification'])
            ->join('skills', 'skills.id', '=', 'employee_skills.skill_id')->orderBy('skills.name')->select('employee_skills.*')
            ->limit(PersonSection::CAP)->get();
    }

    public function find(Employee $employee, int $skillId): EmployeeSkill
    {
        return EmployeeSkill::query()->where('person_id', $employee->person_id)->where('skill_id', $skillId)->with(['skill', 'certification'])->firstOrFail();
    }

    public function add(Employee $employee, array $data): EmployeeSkill
    {
        return DB::transaction(function () use ($employee, $data) {
            $this->lock($employee);
            $skill = Skill::query()->find($data['skill_id']);
            if (! $skill || ! $skill->active) {
                throw ValidationException::withMessages(['skill_id' => ['That skill is not available. Choose a skill from the catalog.']]);
            }
            if (EmployeeSkill::query()->where('person_id', $employee->person_id)->where('skill_id', $skill->id)->exists()) {
                throw ValidationException::withMessages(['skill_id' => ['This skill has already been added to the profile.']]);
            }
            if (EmployeeSkill::query()->where('person_id', $employee->person_id)->count() >= PersonSection::CAP) {
                throw ValidationException::withMessages(['skill_id' => ['You have reached the limit of '.PersonSection::CAP.' skills.']]);
            }
            $row = new EmployeeSkill([
                'skill_id' => $skill->id, 'proficiency' => $data['proficiency'] ?? 'intermediate', 'years_experience' => $data['years_experience'] ?? null,
                'last_used' => $data['last_used'] ?? null, 'verification_status' => 'unverified',
                'certification_id' => $this->certificationId($employee, $data['certification_uuid'] ?? null),
            ]);
            $row->person_id = $employee->person_id;
            $row->save();

            Audit::record('skill_added', 'people', 'employee:'.$employee->uuid, ['skill_id' => $skill->id, 'skill' => $skill->name, 'changes' => array_keys($data)]);
            $this->event($employee, $row, 'Added', 'Skill added: '.$skill->name.'.');

            return $this->find($employee, $skill->id);
        });
    }

    public function update(Employee $employee, int $skillId, array $data): EmployeeSkill
    {
        return DB::transaction(function () use ($employee, $skillId, $data) {
            $this->lock($employee);
            $row = $this->find($employee, $skillId);
            if (array_key_exists('certification_uuid', $data)) {
                $data['certification_id'] = $this->certificationId($employee, $data['certification_uuid']);
                unset($data['certification_uuid']);
            }
            $probe = clone $row;
            $probe->fill($data);
            if ($row->verification_status !== 'unverified' && array_intersect(['proficiency', 'years_experience', 'last_used'], array_keys($probe->getDirty()))) {
                $data += ['verification_status' => 'unverified', 'verified_by' => null, 'verified_at' => null, 'verification_note' => null];
            }
            $row->fill($data);
            $dirty = $row->getDirty();
            if ($dirty === []) {
                return $row;
            }
            $original = collect($dirty)->mapWithKeys(fn ($v, $k) => [$k => $row->getOriginal($k)])->all();
            $row->save();

            Audit::record('skill_updated', 'people', 'employee:'.$employee->uuid, ['skill_id' => $skillId, 'changes' => collect($dirty)->map(fn ($to, $k) => ['from' => $original[$k], 'to' => $to])->all()]);
            $this->event($employee, $row, 'Updated', 'Skill updated: '.$row->skill->name.'.');

            return $this->find($employee, $skillId);
        });
    }

    public function remove(Employee $employee, int $skillId): void
    {
        DB::transaction(function () use ($employee, $skillId) {
            $this->lock($employee);
            $row = $this->find($employee, $skillId);
            $name = $row->skill->name;
            $row->delete();

            Audit::record('skill_removed', 'people', 'employee:'.$employee->uuid, ['skill_id' => $skillId, 'skill' => $name]);
            $this->event($employee, $row, 'Removed', 'Skill removed: '.$name.'.');
        });
    }

    public function verify(Employee $employee, int $skillId, string $status, ?string $note): EmployeeSkill
    {
        return DB::transaction(function () use ($employee, $skillId, $status, $note) {
            $this->lock($employee);
            $row = $this->find($employee, $skillId);
            $verified = $status !== 'unverified';
            $row->verification_status = $status;
            $row->verified_by = $verified ? auth()->id() : null;
            $row->verified_at = $verified ? now() : null;
            $row->verification_note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $row->save();

            Audit::record('skill_verification_'.$status, 'people', 'employee:'.$employee->uuid, ['skill_id' => $skillId, 'status' => $status, 'note' => $row->verification_note]);
            $this->event($employee, $row, 'Verified', 'Skill '.$row->skill->name.' marked '.$status.'.', ['status' => $status]);

            return $this->find($employee, $skillId);
        });
    }

    private function certificationId(Employee $employee, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }
        $id = Str::isUuid($uuid) ? EmployeeCertification::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->value('id') : null;
        if (! $id) {
            throw ValidationException::withMessages(['certification_uuid' => ['That certification was not found on this employee\'s profile.']]);
        }

        return (int) $id;
    }

    private function lock(Employee $employee): void
    {
        Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
    }

    private function event(Employee $employee, EmployeeSkill $row, string $verb, string $summary, array $payload = []): void
    {
        $this->events->record('Skill'.$verb, [
            'employee' => $employee, 'summary' => $summary, 'classification' => Classification::PUBLIC_INTERNAL,
            'payload' => ['skill_id' => $row->skill_id] + $payload, 'subject' => ['skill', $row->skill_id],
        ]);
    }
}
