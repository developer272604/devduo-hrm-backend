<?php

namespace App\Domain\People\Services;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\FieldRegistry;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resource transformer for the employee profile. Every field passes through FieldAccess, so the API
 * never serializes data the viewer may not see; masked fields come back already masked.
 * The response also reports what the viewer may edit so the UI can offer the right action
 * (edit now / request change / read-only) without re-implementing rules.
 */
final class EmployeePresenter
{
    private const PERSON_FIELDS = ['legal_name', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'display_name', 'gender', 'pronouns', 'date_of_birth',
        'marital_status', 'nationality', 'citizenship', 'blood_group', 'personal_email', 'personal_phone', 'locale', 'preferred_language'];

    public function __construct(private FieldAccess $access, private DataScope $scope, private ReportingService $reporting, private AssignmentService $assignments) {}

    public function profile(User $viewer, Employee $employee): array
    {
        $employee->loadMissing('person');
        $person = $employee->person;
        $relation = $this->access->relation($viewer, $employee);
        $view = fn (string $field) => $this->access->view($viewer, $field, $employee, $relation);
        $show = fn (string $field, $value) => match ($view($field)) {
            FieldAccess::FULL => $value, FieldAccess::MASKED => is_string($value) ? FieldAccess::mask($value) : null, default => null,
        };

        $personOut = [];
        foreach (self::PERSON_FIELDS as $field) {
            $key = 'person.'.$field;
            if ($view($key) !== FieldAccess::HIDDEN) {
                $personOut[$field] = $show($key, $person->{$field});
            }
        }
        if ($view('person.photo') !== FieldAccess::HIDDEN) {
            $personOut['has_photo'] = (bool) $person->photo_path;
        }

        $employmentOut = ['uuid' => $employee->uuid, 'person_uuid' => $person->uuid, 'status' => $employee->status, 'status_label' => StatusService::LABELS[$employee->status] ?? $employee->status,
            'employment_sequence' => $employee->employment_sequence, 'is_rehire' => (bool) $employee->rehire_of_id];
        $employmentFields = ['employee_number' => 'employee.employee_number', 'work_email' => 'employee.work_email', 'work_phone' => 'employee.work_phone', 'worker_type' => 'employee.worker_type',
            'joined_on' => 'employee.joined_on', 'original_joining_date' => 'employee.original_joining_date', 'notice_period_days' => 'employee.notice_period_days',
            'retirement_date' => 'employee.retirement_date', 'separated_on' => 'employee.separated_on'];
        foreach ($employmentFields as $column => $key) {
            if ($view($key) !== FieldAccess::HIDDEN) {
                $employmentOut[$column] = $employee->{$column} instanceof \DateTimeInterface ? $employee->{$column}->format('Y-m-d') : $employee->{$column};
            }
        }
        if ($view('employee.probation') !== FieldAccess::HIDDEN) {
            $employmentOut['probation'] = $employee->only(['probation_applicable', 'probation_start', 'probation_end', 'probation_extensions', 'confirmation_date']);
        }
        if ($view('employee.contract') !== FieldAccess::HIDDEN) {
            $employmentOut['contract'] = $employee->only(['contract_start', 'contract_end']);
        }
        $employmentOut += ['timezone' => $employee->timezone, 'country' => $employee->country];

        $job = $this->job($viewer, $employee, $relation);
        $manager = $view('job.manager') !== FieldAccess::HIDDEN ? $this->manager($employee) : null;

        return [
            'uuid' => $employee->uuid,
            'header' => [
                'display_name' => $person->display_name, 'preferred_name' => $view('person.preferred_name') !== FieldAccess::HIDDEN ? $person->preferred_name : null,
                'pronouns' => $view('person.pronouns') !== FieldAccess::HIDDEN ? $person->pronouns : null, 'status' => $employee->status, 'status_label' => StatusService::LABELS[$employee->status] ?? $employee->status,
                'employee_number' => $view('employee.employee_number') !== FieldAccess::HIDDEN ? $employee->employee_number : null,
                'designation' => $job['designation'] ?? null, 'department' => $job['department'] ?? null, 'location' => $job['location'] ?? null, 'manager' => $manager,
                'work_email' => $view('employee.work_email') !== FieldAccess::HIDDEN ? $employee->work_email : null, 'work_phone' => $view('employee.work_phone') !== FieldAccess::HIDDEN ? $employee->work_phone : null,
                'has_photo' => $view('person.photo') !== FieldAccess::HIDDEN && (bool) $person->photo_path,
            ],
            'person' => $personOut, 'employment' => $employmentOut, 'job' => $job,
            'relationships' => $view('job.manager') !== FieldAccess::HIDDEN ? $this->reporting->history($employee) : [],
            'viewer' => [
                'is_self' => $relation['self'], 'is_manager' => $relation['manager'],
                'edit' => collect(array_keys(FieldRegistry::SELF_EDIT_DEFAULTS))->mapWithKeys(fn ($g) => [$g => $this->access->editGroup($viewer, $g, $employee, $relation)])->all(),
                'sections' => $this->visibleSections($viewer, $employee, $relation),
            ],
        ];
    }

    /** Which profile tabs the viewer may open (records inside are still filtered by their own endpoints). */
    private function visibleSections(User $viewer, Employee $employee, array $relation): array
    {
        $map = ['personal' => 'person.legal_name', 'contact' => 'contact.records', 'address' => 'address.records', 'emergency' => 'emergency.records', 'dependents' => 'dependent.records',
            'education' => 'education.records', 'experience' => 'experience.records', 'skills' => 'skill.records', 'certifications' => 'certification.records', 'documents' => 'document.records',
            'identity' => 'identity.records', 'bank' => 'bank.records', 'notes' => 'note.records', 'job' => 'job.department', 'employment' => 'employee.status'];
        $out = [];
        foreach ($map as $section => $field) {
            $mode = $this->access->view($viewer, $field, $employee, $relation);
            if ($section === 'documents' && $relation['self']) {
                $mode = FieldAccess::FULL; // employees always see their own employee-visible documents
            }
            if ($mode !== FieldAccess::HIDDEN) {
                $out[] = $section;
            }
        }

        return $out;
    }

    private function job(User $viewer, Employee $employee, array $relation): array
    {
        $row = $employee->assignmentOn();
        if (! $row) {
            return [];
        }
        $out = ['effective_from' => $row->effective_from->toDateString(), 'effective_to' => $row->effective_to?->toDateString()];
        foreach (Assignment::DIMENSIONS as $column => $table) {
            $name = str_replace('_id', '', $column);
            if ($this->access->view($viewer, 'job.'.$name, $employee, $relation) === FieldAccess::HIDDEN) {
                continue;
            }
            $out[$column] = $row->{$column};
            $out[$name] = $row->{$column} ? DB::table($table)->where('id', $row->{$column})->value('name') : null;
        }
        foreach (['work_mode' => 'job.work_mode', 'employment_category' => 'job.employment_category'] as $column => $field) {
            if ($this->access->view($viewer, $field, $employee, $relation) !== FieldAccess::HIDDEN) {
                $out[$column] = $row->{$column};
            }
        }

        return $out;
    }

    private function manager(Employee $employee): ?array
    {
        $manager = $this->reporting->getManager($employee);

        return $manager ? ['uuid' => $manager->uuid, 'name' => DB::table('people')->where('id', $manager->person_id)->value('display_name')] : null;
    }

    /** Whether privacy rules hide any directory field from coworkers (used by settings screens). */
    public static function privacySnapshot(): array
    {
        return collect(FieldRegistry::PRIVACY_DEFAULTS)->mapWithKeys(fn ($d, $k) => [$k => PeopleSettings::directoryVisible($k)])->all();
    }
}
