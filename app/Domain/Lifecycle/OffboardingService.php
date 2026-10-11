<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Lifecycle\Contracts\{ClearanceRegistry, SettlementInitiator};
use App\Domain\Lifecycle\Models\{Alumnus, ExitInterview, LifecycleCase, LifecycleTask};
use App\Domain\People\Models\{Employee, SeparationRecord};
use App\Models\User;
use Illuminate\Support\Facades\{Crypt, DB};

/** Offboarding specifics: department clearance with blockers, F&F initiation (Payroll), confidential exit interview, alumni and rehire eligibility. */
final class OffboardingService
{
    public function __construct(private ClearanceRegistry $registry, private DomainEvents $events, private CaseService $cases) {}

    /** @return array{cleared:bool,departments:array,blockers:array} */
    public function clearance(LifecycleCase $case): array
    {
        $tasks = LifecycleTask::where('case_id', $case->id)->whereNotNull('clearance_dept')->get();
        $departments = $tasks->groupBy('clearance_dept')->map(fn ($g, $dept) => ['department' => $dept, 'total' => $g->count(), 'pending' => $g->where('status', 'pending')->count(),
            'cleared' => $g->where('status', 'pending')->where('mandatory', true)->isEmpty()])->values()->all();
        $blockers = [];
        foreach ($tasks->where('status', 'pending')->where('mandatory', true) as $t) {
            $blockers[] = ['type' => 'task', 'department' => $t->clearance_dept, 'title' => $t->title, 'task' => $t->uuid, 'owner_user_id' => $t->owner_user_id];
        }
        foreach ($this->registry->blockers($case->employee_id) as $b) {
            $blockers[] = ['type' => 'provider', 'department' => $b['provider'], 'title' => $b['message']];
        }

        return ['cleared' => ! $blockers, 'departments' => $departments, 'blockers' => $blockers];
    }

    /** Starts the Payroll full and final settlement. Refused with 409 (and the blocker list) until every mandatory clearance item is cleared. */
    public function initiateSettlement(User $actor, LifecycleCase $case, array $d): LifecycleCase
    {
        abort_unless($case->kind === 'offboarding', 422, 'Settlement applies to offboarding cases.');
        return DB::transaction(function () use ($actor, $case, $d) {
            $case = LifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->status !== 'cancelled', 409, 'This case was cancelled.');
            if ($case->settlement_uuid) return $case; // idempotent
            $clearance = $this->clearance($case);
            if (! $clearance['cleared']) {
                abort(response()->json(['message' => 'Clearance is incomplete. Settle the open items before starting the final settlement.', 'code' => 'clearance_pending', 'blockers' => $clearance['blockers']], 409));
            }
            $employee = Employee::findOrFail($case->employee_id);
            $last = SeparationRecord::whereKey($case->separation_record_id)->value('last_working_date') ?? $case->anchor_date->toDateString();
            $uuid = app(SettlementInitiator::class)->initiate($actor, $employee, substr((string) $last, 0, 10), $d + ['idempotency_key' => 'lifecycle-'.$case->uuid]);
            $case->update(['settlement_uuid' => $uuid]);
            Audit::record('settlement.initiated', 'lifecycle', $case->uuid, ['settlement' => $uuid]);
            $this->events->record('SettlementInitiated', ['employee' => $employee, 'subject' => ['lifecycle_case', $case->id], 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => 'Full and final settlement started.', 'payload' => ['case' => $case->uuid, 'settlement' => $uuid]]);

            return $case->refresh();
        });
    }

    // ---- exit interview (confidential) ----------------------------------------------------------------------------------------------

    public function submitInterview(User $actor, LifecycleCase $case, array $answers): ExitInterview
    {
        return DB::transaction(function () use ($actor, $case, $answers) {
            $i = ExitInterview::where('case_id', $case->id)->lockForUpdate()->firstOrFail();
            abort_unless($i->status === 'pending', 409, 'The exit interview was already submitted.');
            $i->update(['answers' => Crypt::encryptString(json_encode($answers)), 'status' => 'submitted', 'submitted_at' => now()]);
            Audit::record('exit_interview.submitted', 'lifecycle', $case->uuid);
            LifecycleTask::where('case_id', $case->id)->where('status', 'pending')->where('title', 'like', '%exit interview%')->get()->each(fn ($t) => $this->cases->complete($actor, $t, ['note' => 'Interview submitted']));

            return $i->refresh();
        });
    }

    public function readInterview(User $actor, LifecycleCase $case): array
    {
        $i = ExitInterview::where('case_id', $case->id)->firstOrFail();
        Audit::record('exit_interview.read', 'lifecycle', $case->uuid, ['reader' => $actor->id]);

        return ['status' => $i->status, 'submitted_at' => $i->submitted_at?->toIso8601String(), 'retention_until' => $i->retention_until?->toDateString(),
            'answers' => $i->answers ? json_decode(Crypt::decryptString($i->answers), true) : null];
    }

    // ---- alumni -----------------------------------------------------------------------------------------------------------------------

    public function onSeparated(Employee $employee): Alumnus
    {
        $record = SeparationRecord::where('employee_id', $employee->id)->orderByDesc('id')->first();

        return Alumnus::firstOrCreate(['employee_id' => $employee->id], ['person_id' => $employee->person_id, 'last_working_date' => $employee->separated_on?->toDateString(),
            'separation_type' => $record?->type, 'rehire_eligible' => $record?->rehire_eligible]);
    }

    /** Keeps the alumni record and People's separation record (which rehire reads) in step. */
    public function setEligibility(User $actor, Alumnus $a, ?bool $eligible, ?string $note): Alumnus
    {
        return DB::transaction(function () use ($actor, $a, $eligible, $note) {
            $a->update(['rehire_eligible' => $eligible, 'rehire_note' => $note]);
            SeparationRecord::where('employee_id', $a->employee_id)->where('status', 'completed')->orderByDesc('id')->limit(1)->get()->each(fn ($r) => $r->update(['rehire_eligible' => $eligible]));
            Audit::record('alumni.eligibility', 'lifecycle', $a->uuid, ['eligible' => $eligible]);

            return $a;
        });
    }
}
