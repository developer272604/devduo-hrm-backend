<?php

namespace App\Domain\People\Services;

use App\Domain\Access\Classification;
use App\Domain\Access\PeopleSettings;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Config\CustomFieldService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Person;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Application service for the employee aggregate: creation (full wizard and quick add), updates of
 * person and employment data, and user linkage. Heavy rules live in the focused services it composes
 * (numbering, assignments, reporting, status, duplicates); controllers stay thin.
 */
final class EmployeeService
{
    /** Employment columns HR may edit through updateEmployment(). */
    public const EMPLOYMENT_EDITABLE = ['work_email', 'work_phone', 'worker_type', 'employment_start_date', 'probation_applicable', 'probation_start', 'probation_end', 'contract_start', 'contract_end',
        'notice_period_days', 'retirement_date', 'timezone', 'country'];

    public function __construct(
        private NumberingService $numbering, private AssignmentService $assignments, private ReportingService $reporting,
        private StatusService $status, private DuplicateDetector $duplicates, private DomainEvents $events,
    ) {}

    /**
     * @param  array{person?:array,employment:array,assignment?:array,manager_id?:?int,user_id?:?int,confirm_duplicates?:bool}  $data
     * @param  array{person?:Person,rehire_of?:Employee,source?:string}  $opts  person/rehire_of are used by the rehire flow
     */
    public function create(array $data, array $opts = []): Employee
    {
        $tenantId = app(TenantContext::class)->id;
        $person = $opts['person'] ?? null;
        $employment = $data['employment'];
        $assignment = $data['assignment'] ?? [];

        if (! $person) {
            $candidate = ($data['person'] ?? []) + ['work_email' => $employment['work_email'] ?? null, 'employee_number' => $employment['employee_number'] ?? null];
            $candidate['legal_name'] = Person::composeNames($data['person'])['legal_name'];
            $found = $this->duplicates->find($candidate);
            if ($found && empty($data['confirm_duplicates'])) {
                throw new DuplicateWarning($found);
            }
        }

        return DB::transaction(function () use ($data, $opts, $person, $employment, $assignment, $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(); // serialize numbering/user-link races per tenant
            $country = $this->country($employment, $assignment, $person?->country ?? ($data['person']['country'] ?? null));
            $timezone = $employment['timezone'] ?? $this->timezone($assignment) ?? TenantClock::timezone();
            $joined = CarbonImmutable::parse($employment['joined_on'])->toDateString();

            if (! $person) {
                $personData = Person::composeNames($data['person']);
                $personData['country'] ??= $country;
                $person = Person::create($personData);
            }
            if (! empty($data['user_id'])) {
                $this->linkUser($person, (int) $data['user_id']);
            }
            $context = ['employee_type_id' => $assignment['employee_type_id'] ?? null, 'legal_entity_id' => $assignment['legal_entity_id'] ?? null, 'location_id' => $assignment['location_id'] ?? null,
                'worker_type' => $employment['worker_type'], 'country' => $country];
            if ($this->numbering->isManual()) {
                abort_if(empty($employment['employee_number']), 422, 'Enter an employee number. This workspace uses manual numbering.');
                $number = $employment['employee_number'];
                abort_if(DB::table('employees')->where('tenant_id', $tenantId)->where('employee_number', $number)->exists(), 422, 'This employee number is already in use.');
            } else {
                $number = ! empty($employment['employee_number']) ? $employment['employee_number'] : $this->numbering->next($context);
                abort_if(DB::table('employees')->where('tenant_id', $tenantId)->where('employee_number', $number)->exists(), 422, 'This employee number is already in use.');
            }
            $sequence = ((int) DB::table('employees')->where('tenant_id', $tenantId)->where('person_id', $person->id)->max('employment_sequence')) + 1;
            $status = $employment['status'] ?? ($joined > TenantClock::today($timezone) ? 'preboarding' : (! empty($employment['probation_applicable']) ? 'probation' : 'active'));
            $originalJoin = isset($opts['rehire_of']) ? $opts['rehire_of']->original_joining_date->toDateString() : $joined;

            $employee = Employee::create([
                'person_id' => $person->id, 'employee_number' => $number, 'employment_sequence' => $sequence, 'rehire_of_id' => $opts['rehire_of']->id ?? null,
                'status' => $status, 'worker_type' => $employment['worker_type'], 'original_joining_date' => $originalJoin, 'joined_on' => $joined,
                'employment_start_date' => $joined <= TenantClock::today($timezone) ? $joined : null,
                'probation_applicable' => (bool) ($employment['probation_applicable'] ?? false), 'probation_start' => $employment['probation_start'] ?? (($employment['probation_applicable'] ?? false) ? $joined : null),
                'probation_end' => $employment['probation_end'] ?? $this->defaultProbationEnd($employment, $joined),
                'contract_start' => $employment['contract_start'] ?? null, 'contract_end' => $employment['contract_end'] ?? null, 'notice_period_days' => $employment['notice_period_days'] ?? null,
                'retirement_date' => $employment['retirement_date'] ?? null, 'work_email' => isset($employment['work_email']) ? mb_strtolower($employment['work_email']) : null,
                'work_phone' => $employment['work_phone'] ?? null, 'country' => $country, 'timezone' => $timezone,
            ]);
            DB::table('employee_status_history')->insert(['tenant_id' => $tenantId, 'employee_id' => $employee->id, 'from_status' => null, 'to_status' => $status, 'reason_code' => isset($opts['rehire_of']) ? 'rehire' : 'created',
                'reason' => null, 'effective_date' => $joined, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            $this->assignments->change($employee, $assignment + ['work_mode' => $assignment['work_mode'] ?? 'onsite'], $joined, ['change_type' => 'initial', 'source' => $opts['source'] ?? 'manual', 'reason' => isset($opts['rehire_of']) ? 'Rehire' : 'Joined']);
            // Resolve applicable definitions only after the initial assignment exists (employee-type scoped fields).
            app(CustomFieldService::class)->applyDefaults($employee);
            if (! empty($data['manager_id'])) {
                $manager = Employee::query()->findOrFail($data['manager_id']);
                $this->reporting->assign($employee, $manager, 'primary', $joined, 'Initial reporting line', $opts['source'] ?? 'manual');
            }
            $this->events->record('EmployeeCreated', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $joined, 'classification' => Classification::PUBLIC_INTERNAL,
                'summary' => 'Joined the company on '.$joined.' ('.$employee->employee_number.').', 'payload' => ['employee_number' => $employee->employee_number, 'rehire' => isset($opts['rehire_of'])]]);
            Audit::record('created', 'people', 'employee:'.$employee->uuid, ['number' => $employee->employee_number]);

            return $employee->load('person');
        });
    }

    /** Fast path: minimal fields, remaining profile completed later. Country/timezone derive from organization data. */
    public function quickAdd(array $data): Employee
    {
        return $this->create([
            'person' => $data['person'], 'employment' => $data['employment'], 'assignment' => $data['assignment'] ?? [], 'manager_id' => $data['manager_id'] ?? null,
            'confirm_duplicates' => $data['confirm_duplicates'] ?? false,
        ], ['source' => 'quick_add']);
    }

    /** @param array<string,mixed> $changes subset of EMPLOYMENT_EDITABLE */
    public function updateEmployment(Employee $employee, array $changes): Employee
    {
        return DB::transaction(function () use ($employee, $changes) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $changes = array_intersect_key($changes, array_flip(self::EMPLOYMENT_EDITABLE));
            if (isset($changes['work_email'])) {
                $changes['work_email'] = mb_strtolower($changes['work_email']);
            }
            $employee->fill($changes);
            $dirty = $employee->getDirty();
            if (! $dirty) {
                return $employee;
            }
            $before = array_intersect_key($employee->getOriginal(), $dirty);
            $employee->save();
            $this->events->record('EmployeeUpdated', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'classification' => $this->classificationFor(array_keys($dirty)),
                'summary' => 'Employment details updated: '.implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($dirty))).'.', 'payload' => ['fields' => array_keys($dirty), 'before' => $before]]);
            Audit::record('updated', 'people', 'employee:'.$employee->uuid, ['fields' => array_keys($dirty)]);

            return $employee;
        });
    }

    /** @param array<string,mixed> $changes validated person fields */
    public function updatePerson(Person $person, array $changes, ?Employee $subject = null): Person
    {
        return DB::transaction(function () use ($person, $changes, $subject) {
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            $merged = array_merge($person->only(['legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'display_name']), $changes);
            if (array_intersect(['legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'display_name'], array_keys($changes))) {
                // Display name follows its parts unless explicitly provided, so it never silently goes stale.
                if (! array_key_exists('display_name', $changes)) {
                    $merged['display_name'] = null;
                }
                $changes = array_merge($changes, Person::composeNames($merged));
            }
            $person->fill($changes);
            $dirty = $person->getDirty();
            if (! $dirty) {
                return $person;
            }
            $person->save();
            $this->events->record('PersonalInformationUpdated', ['employee' => $subject, 'person_id' => $person->id, 'subject' => ['person', $person->id], 'classification' => Classification::EMPLOYEE,
                'summary' => 'Personal information updated.', 'payload' => ['fields' => array_keys($dirty)]]);
            Audit::record('updated', 'people', 'person:'.$person->uuid, ['fields' => array_keys($dirty)]);

            return $person;
        });
    }

    /** One login per person; a user account must belong to this workspace and not be linked to someone else. */
    public function linkUser(Person $person, int $userId): void
    {
        $user = User::query()->where('tenant_id', $person->tenant_id)->whereKey($userId)->first();
        abort_unless($user, 422, 'Choose a user from this workspace.');
        abort_if(Person::query()->where('user_id', $userId)->where('id', '!=', $person->id)->exists(), 422, 'That user account is already linked to another person.');
        $person->user_id = $userId;
        $person->save();
        Audit::record('user_linked', 'people', 'person:'.$person->uuid, ['user_id' => $userId]);
    }

    /** The current (or most recent) employment of a person; used to resolve a login to an employee. */
    public function currentEmployment(Person $person): ?Employee
    {
        return Employee::query()->where('person_id', $person->id)->orderByRaw("CASE WHEN status IN ('separated','archived','inactive') THEN 1 ELSE 0 END")->orderByDesc('employment_sequence')->first();
    }

    private function country(array $employment, array $assignment, ?string $personCountry): string
    {
        $country = $employment['country'] ?? null;
        if (! $country && ! empty($assignment['legal_entity_id'])) {
            $country = $this->metadata('legal_entities', $assignment['legal_entity_id'], 'country');
        }
        if (! $country && ! empty($assignment['location_id'])) {
            $country = $this->metadata('locations', $assignment['location_id'], 'country');
        }
        $country ??= PeopleSettings::get('default_country') ?? $personCountry;
        abort_unless($country, 422, 'Choose the country this employee works in.');

        return strtoupper($country);
    }

    private function timezone(array $assignment): ?string
    {
        return ! empty($assignment['location_id']) ? $this->metadata('locations', $assignment['location_id'], 'timezone') : null;
    }

    private function metadata(string $table, int $id, string $key): ?string
    {
        $meta = DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->where('id', $id)->value('metadata');

        return $meta ? (json_decode($meta, true)[$key] ?? null) : null;
    }

    private function defaultProbationEnd(array $employment, string $joined): ?string
    {
        $days = (int) PeopleSettings::get('probation_days', 0);

        return ! empty($employment['probation_applicable']) && $days > 0 ? CarbonImmutable::parse($employment['probation_start'] ?? $joined)->addDays($days)->toDateString() : null;
    }

    private function classificationFor(array $fields): string
    {
        return array_intersect($fields, ['contract_start', 'contract_end', 'notice_period_days', 'retirement_date', 'probation_start', 'probation_end', 'probation_applicable']) ? Classification::HR_CONFIDENTIAL : Classification::PUBLIC_INTERNAL;
    }
}
