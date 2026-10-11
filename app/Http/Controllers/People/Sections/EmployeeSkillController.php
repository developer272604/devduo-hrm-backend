<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeSkill;
use App\Domain\People\Sections\EmployeeSkillService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** /employees/{uuid}/skills[/{skillId}]: catalog skills assigned to a person (addressed by catalog skill id). */
class EmployeeSkillController extends PeopleController
{
    use SectionAuthorization;

    private const FIELD = 'skill.records';

    private const GROUP = 'skill';

    public function __construct(private EmployeeSkillService $skills) {}

    public function index(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, self::FIELD);
        $rows = $this->skills->list($employee);
        $verifiers = $this->verifierNames($rows);
        $certs = $this->certificationsVisible($r, $employee);

        return [
            'data' => $rows->map(fn (EmployeeSkill $row) => $this->present($row, $verifiers, $certs))->values(),
            'viewer' => ['edit' => $this->access()->editGroup($r->user(), self::GROUP, $employee)],
        ];
    }

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, self::FIELD);
        $this->authorizeWrite($r, $employee, self::GROUP);
        $data = $r->validate(['skill_id' => 'required|integer'] + $this->rules(), $this->messages());
        $row = $this->skills->add($employee, $data);

        return response()->json($this->one($r, $employee, $row), 201);
    }

    public function update(Request $r, string $uuid, int $skillId)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, self::FIELD);
        $this->authorizeWrite($r, $employee, self::GROUP);
        $this->skills->find($employee, $skillId);
        $data = $r->validate($this->rules(), $this->messages());
        abort_if($data === [], 422, 'No changes were submitted.');

        return $this->one($r, $employee, $this->skills->update($employee, $skillId, $data));
    }

    public function destroy(Request $r, string $uuid, int $skillId)
    {
        $employee = $this->employee($r, $uuid);
        $this->assertView($r, $employee, self::FIELD);
        $this->authorizeWrite($r, $employee, self::GROUP);
        $this->skills->remove($employee, $skillId);

        return ['deleted' => true];
    }

    public function verify(Request $r, string $uuid, int $skillId)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.verify', false);
        $this->assertView($r, $employee, self::FIELD);
        $input = $this->validateVerification($r);

        return $this->one($r, $employee, $this->skills->verify($employee, $skillId, $input['status'], $input['note']));
    }

    private function rules(): array
    {
        return [
            'proficiency' => ['sometimes', 'required', 'in:'.implode(',', EmployeeSkillService::PROFICIENCIES)],
            'years_experience' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:80'],
            'last_used' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'certification_uuid' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    private function messages(): array
    {
        return [
            'skill_id.required' => 'Choose a skill from the catalog.',
            'proficiency.in' => 'Proficiency must be beginner, intermediate, advanced or expert.',
            'years_experience.numeric' => 'Enter the years of experience as a number.',
            'years_experience.max' => 'Years of experience cannot be more than 80.',
            'last_used.date_format' => 'Enter dates as YYYY-MM-DD.',
        ];
    }

    private function one(Request $r, Employee $employee, EmployeeSkill $row): array
    {
        return $this->present($row, $this->verifierNames(new Collection([$row])), $this->certificationsVisible($r, $employee));
    }

    /** Certifications are employee-class data: a skill only names its certification to viewers who may see them. */
    private function certificationsVisible(Request $r, Employee $employee): bool
    {
        return $this->access()->view($r->user(), 'certification.records', $employee) !== FieldAccess::HIDDEN;
    }

    private function present(EmployeeSkill $row, array $verifiers, bool $certs): array
    {
        return [
            'skill' => ['id' => $row->skill->id, 'name' => $row->skill->name, 'category' => $row->skill->category, 'active' => (bool) $row->skill->active],
            'proficiency' => $row->proficiency, 'years_experience' => $row->years_experience, 'last_used' => $this->date($row->last_used),
            'certification_uuid' => $certs ? $row->certification?->uuid : null, 'certification_name' => $certs ? $row->certification?->name : null,
            'verification' => $this->verification($row, $verifiers),
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
