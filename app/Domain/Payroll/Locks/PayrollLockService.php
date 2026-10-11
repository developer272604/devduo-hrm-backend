<?php

namespace App\Domain\Payroll\Locks;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Models\PayrollApproval;
use App\Domain\Payroll\Models\PayrollLock;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Runs\PeriodSync;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\Payroll\Validation\ValidationEngine;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review -> approval -> approved -> locked, and the controlled reopen. Maker-checker through payroll_approvals + My Work tasks (titles carry no
 * amounts). The run's `created_by` and the person who submitted it can never approve it when approvals.run_checker is on.
 */
final class PayrollLockService
{
    public function __construct(private ValidationEngine $validation, private ResultStore $store) {}

    public function submit(PayrollRun $run, User $actor): PayrollRun
    {
        $this->validation->validate($run->fresh()); // persisted before the gate so a refusal keeps its findings

        return $this->in($run, function (PayrollRun $run) use ($actor) {
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, 'approval', 'status');
            $summary = $this->validation->summary($run);
            if ($summary['blocking'] > 0 || $summary['critical'] > 0) {
                throw ValidationException::withMessages(['run' => "The run has {$summary['blocking']} blocking and {$summary['critical']} critical finding(s). Resolve or waive them (critical findings cannot be waived) before approval."]);
            }
            $run->update(['status' => 'approval', 'stage' => 7]);
            PayrollApproval::create(['subject_type' => 'run', 'subject_id' => $run->id, 'step' => 1, 'role' => 'approver', 'status' => 'pending', 'requested_by' => $actor->id]);
            $this->notify('payroll.approve', [$actor->id, $run->created_by], 'Payroll run ready for approval', 'run:'.$run->uuid, '/payroll/runs/'.$run->uuid);
            PeriodSync::follow($run->period, 'approval', $actor->id);
            Audit::record('payroll.run.submitted', 'payroll', $run->uuid);

            return $run;
        });
    }

    public function decide(PayrollRun $run, User $actor, string $decision, ?string $comment = null): PayrollRun
    {
        return $this->in($run, function (PayrollRun $run) use ($actor, $decision, $comment) {
            abort_unless($run->status === 'approval', 409, 'This run is not waiting for approval.');
            $approval = PayrollApproval::where('subject_type', 'run')->where('subject_id', $run->id)->where('status', 'pending')->latest('id')->firstOrFail();
            if (PayrollSettings::get('approvals.run_checker', true)) {
                abort_if(in_array($actor->id, [$approval->requested_by, $run->created_by], true), 403, 'A different authorized person must approve this payroll run.');
            }
            $approval->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'comment' => $comment]);
            app(TaskService::class)->completeBySource('payroll', 'run:'.$run->uuid);
            if ($decision === 'approved') {
                $run->update(['status' => 'approved', 'stage' => 8, 'approved_by' => $actor->id, 'approved_at' => now()]);
                PeriodSync::follow($run->period, 'approved', $actor->id);
                $this->event(PayrollEvents::PAYROLL_APPROVED, 'Payroll run approved', $run, $actor->id);
            } else {
                $run->update(['status' => 'review', 'stage' => 5]);
                PeriodSync::follow($run->period, 'review', $actor->id, 'Approval rejected');
            }
            Audit::record('payroll.run.'.$decision, 'payroll', $run->uuid);

            return $run;
        });
    }

    public function lock(PayrollRun $run, User $actor, ?string $note = null): PayrollRun
    {
        return $this->in($run, function (PayrollRun $run) use ($actor, $note) {
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, 'locked', 'status');
            // tamper check: every current result must still match its checksum before it becomes the official record
            foreach (DB::table('payroll_calculation_results')->where('run_id', $run->id)->where('is_current', true)->pluck('id') as $id) {
                $result = \App\Domain\Payroll\Models\PayrollCalculationResult::find($id);
                abort_unless($this->store->verify($result), 409, 'A stored payroll result failed its integrity check. The run cannot be locked.');
            }
            PayrollLock::create(['period_id' => $run->period_id, 'run_id' => $run->id, 'status' => 'locked', 'locked_by' => $actor->id, 'locked_at' => now(), 'lock_note' => $note]);
            DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('period_id', $run->period_id)->where('status', 'approved')->update(['status' => 'consumed', 'consumed_run_id' => $run->id]);
            DB::table('payroll_adjustments')->where('tenant_id', $run->tenant_id)->where('status', 'approved')->where(fn ($q) => $q->where('run_id', $run->id)->orWhere(fn ($w) => $w->whereNull('run_id')->where('settlement_period_id', $run->period_id)))
                ->update(['status' => 'applied', 'run_id' => $run->id]);
            $run->update(['status' => 'locked', 'stage' => 8, 'locked_by' => $actor->id, 'locked_at' => now()]);
            if ($run->run_type === 'regular') {
                app(\App\Domain\Payroll\Loans\LoanService::class)->consume($run);
            }
            if ($run->run_type === 'regular') {
                PeriodSync::follow($run->period, 'locked', $actor->id);
            }
            $this->event(PayrollEvents::PAYROLL_LOCKED, 'Payroll run locked', $run, $actor->id);
            Audit::record('payroll.run.locked', 'payroll', $run->uuid);

            return $run;
        });
    }

    public function requestReopen(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        return $this->in($run, function (PayrollRun $run) use ($actor, $reason) {
            abort_unless(in_array($run->status, ['locked', 'outputs', 'payment', 'closed'], true), 409, 'Only a locked run can be reopened.');
            if (strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => 'Explain why the run must be reopened (at least 10 characters).']);
            }
            $lock = PayrollLock::where('run_id', $run->id)->where('status', 'locked')->latest('id')->firstOrFail();
            $lock->update(['status' => 'reopen_requested', 'reopen_requested_by' => $actor->id, 'reopen_reason' => $reason]);
            PayrollApproval::create(['subject_type' => 'unlock', 'subject_id' => $run->id, 'step' => 1, 'role' => 'approver', 'status' => 'pending', 'requested_by' => $actor->id, 'comment' => $reason]);
            $this->notify('payroll.unlock.approve', [$actor->id], 'Payroll reopen request', 'unlock:'.$run->uuid, '/payroll/runs/'.$run->uuid);
            Audit::record('payroll.run.reopen_requested', 'payroll', $run->uuid, ['reason' => $reason]);

            return $run;
        });
    }

    public function decideReopen(PayrollRun $run, User $actor, string $decision, ?string $comment = null): PayrollRun
    {
        return $this->in($run, function (PayrollRun $run) use ($actor, $decision, $comment) {
            $lock = PayrollLock::where('run_id', $run->id)->where('status', 'reopen_requested')->latest('id')->first();
            abort_unless($lock, 409, 'There is no pending reopen request for this run.');
            if (PayrollSettings::get('approvals.unlock_checker', true)) {
                abort_if($lock->reopen_requested_by === $actor->id, 403, 'A different authorized person must approve reopening this run.');
            }
            PayrollApproval::where('subject_type', 'unlock')->where('subject_id', $run->id)->where('status', 'pending')->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'comment' => $comment]);
            app(TaskService::class)->completeBySource('payroll', 'unlock:'.$run->uuid);
            if ($decision === 'rejected') {
                $lock->update(['status' => 'locked']);
                Audit::record('payroll.run.reopen_rejected', 'payroll', $run->uuid);

                return $run;
            }
            $lock->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now(), 'post_lock_changes' => []]);
            DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('consumed_run_id', $run->id)->update(['status' => 'approved', 'consumed_run_id' => null]);
            DB::table('payroll_adjustments')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->where('status', 'applied')->update(['status' => 'approved']);
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, 'reopened', 'status');
            app(\App\Domain\Payroll\Loans\LoanService::class)->release($run);
            $run->update(['status' => 'reopened', 'stage' => 2, 'approved_by' => null, 'approved_at' => null, 'locked_by' => null, 'locked_at' => null]);
            if ($run->run_type === 'regular') {
                PeriodSync::follow($run->period, 'reopened', $actor->id, $lock->reopen_reason);
            }
            $this->event(PayrollEvents::PAYROLL_REOPENED, 'Payroll run reopened', $run, $actor->id);
            Audit::record('payroll.run.reopened', 'payroll', $run->uuid, ['reason' => $lock->reopen_reason]);

            return $run;
        });
    }

    /** Appends to the change log of the lock that was released for this run (everything done after a reopen is traceable). */
    public static function logChange(PayrollRun $run, string $what, array $meta = []): void
    {
        if ($run->locked_at !== null) {
            return;
        }
        $lock = PayrollLock::where('run_id', $run->id)->where('status', 'released')->latest('id')->first();
        if ($lock) {
            $changes = $lock->post_lock_changes ?? [];
            $changes[] = ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'change' => $what] + ($meta ? ['meta' => $meta] : []);
            $lock->update(['post_lock_changes' => $changes]);
        }
    }

    private function in(PayrollRun $run, callable $fn): PayrollRun
    {
        return DB::transaction(fn () => $fn(PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail()));
    }

    private function event(string $type, string $summary, PayrollRun $run, int $actorId): void
    {
        app(DomainEvents::class)->record($type, ['summary' => $summary, 'payload' => ['run_uuid' => $run->uuid, 'period_id' => $run->period_id], 'actor_id' => $actorId]);
    }

    /** @param array<int,?int> $exclude */
    private function notify(string $permission, array $exclude, string $title, string $source, string $action): void
    {
        $users = User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->whereNotIn('id', array_filter($exclude))
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', $permission))->get();
        foreach ($users as $user) {
            app(TaskService::class)->createOnce(['title' => $title, 'description' => 'Open the payroll run to review it.', 'assignee_id' => $user->id, 'status' => 'open', 'priority' => 'normal',
                'source_module' => 'payroll', 'source_entity' => $source, 'action' => $action, 'metadata' => ['run_uuid' => substr($source, strpos($source, ':') + 1)]]);
        }
    }
}
