<?php

namespace App\Domain\People\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\ReportingRelationship;
use App\Domain\People\Models\SeparationRecord;
use App\Domain\People\Services\EmployeeService;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Separation, rehire and retention foundation (no full offboarding). Status changes always go through StatusService; the "became separated"
 * consequences (close open assignments and reporting lines at the last working date, deactivate the login, finish the separation record) run
 * from onSeparated(), which LifecycleBoot calls for every transition to separated, whether manual, bulk or from the nightly lifecycle job.
 */
final class SeparationService
{
    public const TYPES = ['resignation', 'termination', 'retirement', 'contract_end', 'absconding', 'other'];

    private const INITIATE_FROM = ['active', 'probation', 'suspended', 'long_leave', 'notice_period'];

    public function __construct(private StatusService $status, private DomainEvents $events, private EmployeeService $employees) {}

    /** @param array{type:string,last_working_date:string,reason_code?:?string,reason?:?string,rehire_eligible?:?bool,comments?:?string} $data */
    public function initiate(User $actor, Employee $employee, array $data): SeparationRecord
    {
        return DB::transaction(function () use ($actor, $employee, $data) {
            $employee = $this->lock($employee);
            abort_unless(in_array($employee->status, self::INITIATE_FROM, true), 422, 'Only employees who are currently working can be separated.');
            abort_if($this->open($employee), 422, 'A separation is already in progress for this employee.');
            $last = $data['last_working_date'];
            abort_if($last < $employee->joined_on->toDateString(), 422, 'The last working date cannot be before the joining date.');

            if ($employee->status !== 'notice_period') {
                $employee = $this->status->transition($employee, 'notice_period', ['reason_code' => 'separation_'.$data['type'], 'reason' => 'Separation initiated', 'effective_date' => TenantClock::today($employee->timezone)]);
            }
            $employee->separated_on = $last;
            $employee->save();
            $row = SeparationRecord::query()->create(['employee_id' => $employee->id, 'type' => $data['type'], 'reason_code' => $data['reason_code'] ?? null, 'reason' => $data['reason'] ?? null,
                'initiated_on' => TenantClock::today($employee->timezone), 'initiated_by' => $actor->id, 'last_working_date' => $last, 'status' => 'initiated',
                'rehire_eligible' => $data['rehire_eligible'] ?? null, 'comments' => $data['comments'] ?? null]);
            Audit::record('separation_initiated', 'people', 'employee:'.$employee->uuid, ['record' => $row->uuid, 'type' => $row->type, 'last_working_date' => $last]);
            $this->events->record('SeparationInitiated', ['employee' => $employee, 'subject' => ['separation_record', $row->id], 'effective_date' => $last, 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Separation initiated ('.str_replace('_', ' ', $row->type).'), last working date '.$last.'.', 'payload' => ['record' => $row->uuid, 'type' => $row->type]]);

            return $row;
        });
    }

    /** Moves the employee to separated once the last working date has arrived; finishing the record happens in onSeparated(). */
    public function complete(User $actor, Employee $employee, array $data = []): SeparationRecord
    {
        return DB::transaction(function () use ($actor, $employee, $data) {
            $employee = $this->lock($employee);
            $row = $this->open($employee);
            abort_unless($row, 422, 'There is no separation in progress for this employee.');
            $last = $row->last_working_date->toDateString();
            abort_if($last > TenantClock::today($employee->timezone), 422, 'The separation can be completed on or after the last working date ('.$last.').');
            $updates = array_filter(['rehire_eligible' => $data['rehire_eligible'] ?? null, 'comments' => $data['comments'] ?? null], fn ($v) => $v !== null);
            if ($updates) {
                $row->fill($updates)->save();
            }
            if ($employee->status !== 'separated') {
                $this->status->transition($employee, 'separated', ['reason_code' => 'separation_'.$row->type, 'reason' => 'Separation completed', 'effective_date' => $last]);
            }

            return $row->refresh();
        });
    }

    public function withdraw(User $actor, Employee $employee, ?string $comments): SeparationRecord
    {
        return DB::transaction(function () use ($employee, $comments) {
            $employee = $this->lock($employee);
            $row = $this->open($employee);
            abort_unless($row, 422, 'There is no separation in progress for this employee.');
            abort_unless($employee->status === 'notice_period', 422, 'The employee has already separated. Use rehire instead.');
            $back = $employee->probation_applicable && ! $employee->confirmation_date && $employee->probation_end ? 'probation' : 'active';
            $employee = $this->status->transition($employee, $back, ['reason_code' => 'separation_withdrawn', 'reason' => 'Separation withdrawn', 'effective_date' => TenantClock::today($employee->timezone)]);
            $employee->separated_on = null;
            $employee->save();
            $row->fill(['status' => 'withdrawn', 'comments' => $comments ?? $row->comments])->save();
            Audit::record('separation_withdrawn', 'people', 'employee:'.$employee->uuid, ['record' => $row->uuid]);
            $this->events->record('SeparationWithdrawn', ['employee' => $employee, 'subject' => ['separation_record', $row->id], 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Separation withdrawn.', 'payload' => ['record' => $row->uuid]]);

            return $row;
        });
    }

    /** Idempotent consequences of an employee becoming separated. */
    public function onSeparated(Employee $employee): void
    {
        $employee = Employee::query()->with('person')->findOrFail($employee->id);
        $last = $employee->separated_on?->toDateString() ?? TenantClock::today($employee->timezone);
        $open = fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $last);
        $assignments = Assignment::query()->where('employee_id', $employee->id)->where('effective_from', '<=', $last)->where($open)->update(['effective_to' => $last]);
        $lines = ReportingRelationship::query()->where('effective_from', '<=', $last)->where($open)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere('manager_id', $employee->id))->update(['effective_to' => $last]);

        $record = $this->open($employee);
        if ($record) {
            $record->fill(['status' => 'completed'])->save();
        }
        $deactivated = $this->deactivateLogin($employee);
        Audit::record('separated', 'people', 'employee:'.$employee->uuid, ['assignments_closed' => $assignments, 'relationships_closed' => $lines, 'login_deactivated' => $deactivated]);
        $this->events->record('EmployeeSeparated', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $last, 'classification' => Classification::PUBLIC_INTERNAL,
            'summary' => 'Separated; last working date '.$last.'.', 'payload' => ['separation_type' => $record?->type, 'record' => $record?->uuid, 'rehire_eligible' => $record?->rehire_eligible]]);
    }

    /** The login is deactivated only when the person has no other working employment. */
    private function deactivateLogin(Employee $employee): bool
    {
        $userId = $employee->person?->user_id;
        if (! $userId) {
            return false;
        }
        $other = Employee::query()->where('person_id', $employee->person_id)->where('id', '!=', $employee->id)->whereIn('status', array_merge(Employee::WORKING, ['preboarding']))->exists();
        if ($other) {
            return false;
        }

        return User::query()->where('tenant_id', $employee->tenant_id)->whereKey($userId)->where('active', true)->update(['active' => false, 'session_version' => DB::raw('session_version + 1')]) > 0;
    }

    // ---- rehire ---------------------------------------------------------------------------------

    /**
     * Creates a new employment period for the same person. Needs rehire eligibility on the latest separation unless an override reason is given.
     *
     * @param  array{employment:array,assignment?:array,manager_id?:?int,override_reason?:?string}  $data
     */
    public function rehire(User $actor, Employee $previous, array $data): Employee
    {
        return DB::transaction(function () use ($actor, $previous, $data) {
            $previous = $this->lock($previous);
            abort_unless(in_array($previous->status, ['separated', 'archived', 'inactive'], true), 422, 'Only a separated employee can be rehired.');
            $person = Person::query()->findOrFail($previous->person_id);
            abort_if($person->anonymized_at, 422, 'This person record has been anonymized and cannot be rehired.');
            abort_if(Employee::query()->where('person_id', $person->id)->whereIn('status', array_merge(Employee::WORKING, ['preboarding']))->exists(), 422, 'This person already has a current employment.');
            $latest = Employee::query()->where('person_id', $person->id)->orderByDesc('employment_sequence')->first();
            abort_if($latest->id !== $previous->id, 422, 'Rehire from the most recent employment of this person.');

            $record = SeparationRecord::query()->where('employee_id', $previous->id)->where('status', 'completed')->orderByDesc('id')->first();
            $override = trim((string) ($data['override_reason'] ?? ''));
            $overridden = false;
            if (! $record || $record->rehire_eligible !== true) {
                if ($override === '') {
                    throw ValidationException::withMessages(['override_reason' => ['This person is not marked eligible for rehire. Give a reason to override.']]);
                }
                $overridden = true;
            }
            $joined = $data['employment']['joined_on'];
            if ($previous->separated_on && $joined <= $previous->separated_on->toDateString()) {
                throw ValidationException::withMessages(['employment.joined_on' => ['The new joining date must be after the previous last working date ('.$previous->separated_on->toDateString().').']]);
            }
            $employee = $this->employees->create(['employment' => $data['employment'], 'assignment' => $data['assignment'] ?? [], 'manager_id' => $data['manager_id'] ?? null],
                ['person' => $person, 'rehire_of' => $previous, 'source' => 'rehire']);
            if ($person->user_id) {
                User::query()->where('tenant_id', $person->tenant_id)->whereKey($person->user_id)->update(['active' => true]);
            }
            Audit::record('rehired', 'people', 'employee:'.$employee->uuid, ['previous' => $previous->uuid, 'override' => $overridden] + ($overridden ? ['override_reason' => $override] : []));
            $this->events->record('EmployeeRehired', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $joined, 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Rehired (employment '.$employee->employment_sequence.').', 'payload' => ['previous_employee' => $previous->uuid, 'employment_sequence' => $employee->employment_sequence, 'eligibility_overridden' => $overridden]]);

            return $employee;
        });
    }

    // ---- retention ------------------------------------------------------------------------------

    public function setRetention(User $actor, Person $person, array $data): Person
    {
        return DB::transaction(function () use ($person, $data) {
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            abort_if($person->anonymized_at, 422, 'This person record has been anonymized.');
            $before = ['legal_hold' => $person->legal_hold, 'retention_until' => $person->retention_until?->toDateString()];
            if (array_key_exists('legal_hold', $data)) {
                $person->legal_hold = (bool) $data['legal_hold'];
            }
            if (array_key_exists('retention_until', $data)) {
                $person->retention_until = $data['retention_until'];
            }
            $person->save();
            $after = ['legal_hold' => $person->legal_hold, 'retention_until' => $person->retention_until?->toDateString()];
            Audit::record('retention_updated', 'people', 'person:'.$person->uuid, ['before' => $before, 'after' => $after, 'reason' => $data['reason'] ?? null]);
            $this->events->record('RetentionUpdated', ['person_id' => $person->id, 'subject' => ['person', $person->id], 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => $person->legal_hold ? 'Legal hold in place.' : 'Retention settings updated.', 'payload' => ['legal_hold' => $person->legal_hold, 'retention_until' => $after['retention_until']]]);

            return $person;
        });
    }

    /**
     * Anonymization architecture: strips direct identifiers from the person and archives past employments. Nothing is physically deleted, and a
     * legal hold or an unexpired retention period refuses it. Section records (contacts, documents, ...) are purged by a later retention job.
     */
    public function anonymize(User $actor, Person $person, string $reason): Person
    {
        return DB::transaction(function () use ($person, $reason) {
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            abort_if($person->anonymized_at, 422, 'This person record has already been anonymized.');
            if ($person->legal_hold) {
                abort(response()->json(['message' => 'This person is under legal hold and cannot be anonymized.', 'code' => 'legal_hold'], 409));
            }
            if ($person->retention_until && $person->retention_until->toDateString() > TenantClock::today()) {
                abort(response()->json(['message' => 'The retention period runs until '.$person->retention_until->toDateString().'.', 'code' => 'retention_active'], 409));
            }
            $employments = Employee::query()->where('person_id', $person->id)->get();
            abort_if($employments->contains(fn ($e) => in_array($e->status, array_merge(Employee::WORKING, ['preboarding']), true)), 422, 'Only people who have left can be anonymized.');
            foreach ($employments as $employment) {
                if ($employment->status !== 'archived') {
                    $this->status->transition($employment, 'archived', ['reason_code' => 'anonymized', 'reason' => 'Anonymization']);
                }
                Employee::query()->whereKey($employment->id)->update(['work_email' => null, 'work_phone' => null]);
            }
            $person->fill(['legal_first_name' => 'Anonymized', 'legal_middle_name' => null, 'legal_last_name' => null, 'legal_name' => 'Anonymized', 'preferred_name' => null,
                'display_name' => 'Anonymized person', 'gender' => null, 'pronouns' => null, 'date_of_birth' => null, 'marital_status' => null, 'blood_group' => null, 'personal_email' => null,
                'personal_phone' => null, 'photo_path' => null, 'anonymized_at' => now(), 'archived_at' => $person->archived_at ?? now()])->save();
            if ($person->user_id) {
                User::query()->where('tenant_id', $person->tenant_id)->whereKey($person->user_id)->update(['active' => false, 'session_version' => DB::raw('session_version + 1')]);
            }
            Audit::record('anonymized', 'people', 'person:'.$person->uuid, ['reason' => $reason]);
            $this->events->record('PersonAnonymized', ['person_id' => $person->id, 'subject' => ['person', $person->id], 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Personal data anonymized.', 'payload' => ['employments' => $employments->count()]]);

            return $person;
        });
    }

    // ---- reads ----------------------------------------------------------------------------------

    public function present(SeparationRecord $row): array
    {
        return ['uuid' => $row->uuid, 'type' => $row->type, 'status' => $row->status, 'reason_code' => $row->reason_code, 'reason' => $row->reason, 'initiated_on' => $row->initiated_on->toDateString(),
            'initiated_by' => $row->initiated_by ? User::query()->whereKey($row->initiated_by)->value('name') : null, 'last_working_date' => $row->last_working_date->toDateString(),
            'rehire_eligible' => $row->rehire_eligible, 'comments' => $row->comments, 'workflow_ref' => $row->workflow_ref, 'clearance_ref' => $row->clearance_ref];
    }

    private function open(Employee $employee): ?SeparationRecord
    {
        return SeparationRecord::query()->where('employee_id', $employee->id)->where('status', 'initiated')->orderByDesc('id')->first();
    }

    private function lock(Employee $employee): Employee
    {
        return Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }
}
