<?php

namespace App\Domain\Recruitment;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Lifecycle\SeparationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\EmployeeService;
use App\Domain\Recruitment\Models\Handover;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Recruitment\Models\Requisition;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Accepted offer -> Person + Employment through People's EmployeeService (numbering, assignment, duplicate detection). One handover per offer/application. */
final class HandoverService
{
    public function __construct(private EmployeeService $employees, private DomainEvents $events) {}

    public function handover(User $actor, string $offerUuid, array $data): Handover
    {
        abort_unless($actor->allows('recruitment.handover') && $actor->allows('people.manage'), 403);

        return DB::transaction(function () use ($actor, $offerUuid, $data) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $offer = app(OfferService::class)->find($actor, $offerUuid, 'recruitment.handover', true);
            if ($existing = Handover::where('offer_id', $offer->id)->first()) {
                return $existing;
            } // idempotent retry
            abort_unless($offer->status === 'accepted', 409, 'Only an accepted offer can be handed over.');
            $app = $offer->application()->lockForUpdate()->first();
            abort_unless($app->status === 'active' && ! $app->employee_id, 409, 'This application was already closed or converted.');
            $req = Requisition::whereKey($app->requisition_id)->lockForUpdate()->firstOrFail();
            abort_unless($req->status === 'open' && $req->filled_count < $req->openings, 409, 'The requisition has no open headcount left.');
            $candidate = $app->candidate;
            abort_if($candidate->anonymized_at, 409, 'Applicant data was anonymized and cannot be converted.');

            $parts = preg_split('/\s+/', trim($candidate->name), 2);
            $assignment = ($data['assignment'] ?? []) + array_filter(['legal_entity_id' => $req->legal_entity_id, 'position_id' => $req->position_id, 'department_id' => $req->department_id, 'location_id' => $req->location_id]);
            $employeeData = [
                'person' => ['legal_first_name' => $parts[0], 'legal_last_name' => $parts[1] ?? null, 'personal_email' => $candidate->email, 'date_of_birth' => $data['date_of_birth'] ?? null],
                'employment' => ['worker_type' => $data['worker_type'] ?? 'employee', 'joined_on' => $offer->start_date->toDateString(), 'employee_number' => $data['employee_number'] ?? null,
                    'work_email' => $data['work_email'] ?? null, 'probation_applicable' => (bool) ($data['probation_applicable'] ?? false)],
                'assignment' => $assignment, 'manager_id' => isset($data['manager_uuid']) ? Employee::where('uuid', $data['manager_uuid'])->firstOrFail()->id : null,
                'confirm_duplicates' => (bool) ($data['confirm_duplicates'] ?? false),
            ];
            if (! empty($data['previous_employee_uuid'])) {
                abort_unless($actor->allows('people.rehire'), 403);
                abort_unless(! empty($data['rehire_identity_confirmed']), 422, 'Confirm that the candidate and former employee are the same person.');
                $previous = app(DataScope::class)->constrain(Employee::query(), $actor, 'people.rehire')
                    ->where('uuid', $data['previous_employee_uuid'])->firstOrFail();
                $employee = app(SeparationService::class)->rehire($actor, $previous, $employeeData + ['override_reason' => $data['override_reason'] ?? null]);
            } else {
                $employee = $this->employees->create($employeeData, ['source' => 'recruitment']);
            }

            $h = Handover::create(['offer_id' => $offer->id, 'application_id' => $app->id, 'employee_id' => $employee->id, 'person_id' => $employee->person_id, 'source' => $app->source, 'performed_by' => $actor->id]);
            $app->update(['status' => 'hired', 'employee_id' => $employee->id, 'closed_at' => now()]);
            $filled = $req->filled_count + 1;
            $req->update(['filled_count' => $filled, 'filled_at' => $filled >= $req->openings ? now() : null, 'status' => $filled >= $req->openings ? 'filled' : 'open']);
            app(TaskService::class)->completeBySource('recruitment', 'handover:'.$offer->uuid);
            RecruitmentNotify::tell($req->recruiter_id, 'Candidate converted', $candidate->name.' now has an employee record ('.$employee->employee_number.').');
            $this->events->record('RecruitmentHired', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $offer->start_date->toDateString(),
                'summary' => 'Hired through recruitment ('.($app->source ?: 'unspecified source').').', 'payload' => ['application' => $app->uuid, 'requisition' => $req->uuid]]);
            Audit::record('handover.completed', 'recruitment', $offer->uuid, ['employee' => $employee->uuid, 'rehire_of' => $data['previous_employee_uuid'] ?? null]);

            return $h;
        });
    }
}
