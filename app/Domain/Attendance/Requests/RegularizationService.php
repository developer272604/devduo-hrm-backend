<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Models\RegularizationAction;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attendance regularization: employee requests a correction, steps (manager -> HR | manager | HR) are recorded in
 * attendance_regularization_actions, each step is a My Work task, approval asks the processor to recalculate the day.
 * Raw punches are never touched; the processor builds virtual punches from requested_in / requested_out of APPROVED rows.
 */
final class RegularizationService
{
    public const TYPES = ['forgot_in' => 'Forgot check-in', 'forgot_out' => 'Forgot check-out', 'wrong_punch' => 'Wrong punch', 'offsite' => 'Worked offsite',
        'incorrect' => 'Attendance incorrect', 'client_visit' => 'Client visit', 'official_duty' => 'Official duty'];

    public function __construct(private RequestSupport $support, private ApproverResolver $approvers, private DomainEvents $events) {}

    /** @return array{0:Regularization,1:bool} the request and whether it was newly created (false = idempotent replay) */
    public function submit(User $actor, Employee $employee, array $data, ?UploadedFile $file = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = Regularization::query()->where('idempotency_key', $idempotencyKey)->first())) {
            abort_unless($existing->employee_id === $employee->id, 409, 'That idempotency key was already used for a different request.');

            return [$existing, false];
        }
        $n = $this->normalize($employee, $data);
        $rules = RequestRules::regularization($employee, $n['date']);
        abort_unless($rules['enabled'] ?? true, 422, 'Regularization is not enabled for your attendance policy. Please contact HR.');
        $today = RequestSupport::today($employee);
        if ($n['date'] > $today && ! ($rules['allow_future'] ?? false)) {
            throw ValidationException::withMessages(['date' => 'You cannot regularize a future date.']);
        }
        $earliest = CarbonImmutable::parse($today)->subDays($rules['window_days'])->toDateString();
        if ($n['date'] < $earliest) {
            throw ValidationException::withMessages(['date' => "Regularization requests are accepted for the last {$rules['window_days']} days. This date is outside that window; ask HR for a manual adjustment."]);
        }
        $this->assertJoined($employee, $n['date']);
        if (($max = $rules['max_per_month'] ?? null) !== null) {
            $month = substr($n['date'], 0, 7);
            $used = Regularization::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->where('attendance_date', '>=', $month.'-01')->where('attendance_date', '<=', $month.'-31')->count();
            if ($used >= (int) $max) {
                throw ValidationException::withMessages(['date' => "You have used all $max regularization requests allowed for this month."]);
            }
        }
        if (Regularization::query()->where('employee_id', $employee->id)->where('attendance_date', $n['date'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['date' => 'You already have a pending regularization for this date. Withdraw it first if you need to change it.']);
        }
        $this->support->assertUnlocked($employee->id, $n['date']);
        $steps = $this->approvers->roles($employee, 'regularization', $n['date']);
        foreach (array_keys($steps) as $i) {
            abort_if($this->approvers->candidates($employee, 'regularization', $n['date'], $i + 1)->isEmpty(), 422, 'No approver is available for your request yet. Please ask HR to assign your reporting manager.');
        }

        $uuid = (string) Str::uuid();
        $attachment = $file ? $this->support->storeAttachment($file, 'regularizations', $uuid) : null;

        $request = DB::transaction(function () use ($actor, $employee, $n, $steps, $uuid, $attachment, $idempotencyKey) {
            $request = Regularization::create(['uuid' => $uuid, 'employee_id' => $employee->id, 'attendance_date' => $n['date'], 'type' => $n['type'], 'current_snapshot' => $this->snapshot($employee, $n['date']),
                'requested_in' => $n['in'], 'requested_out' => $n['out'], 'reason' => $n['reason'], 'comments' => $n['comments'], 'attachment_path' => $attachment['path'] ?? null, 'status' => 'pending',
                'current_step' => 1, 'total_steps' => count($steps), 'submitted_by' => $actor->id, 'idempotency_key' => $idempotencyKey]);
            $this->log($request, 1, 'submit', $actor->id, null, $n['reason']);
            $this->openStep($request, $employee);
            Audit::record('submitted', 'attendance', 'regularization:'.$request->uuid, ['employee' => $employee->uuid, 'date' => $n['date'], 'type' => $n['type'], 'steps' => count($steps)]);
            $this->events->record('RegularizationSubmitted', ['employee' => $employee, 'subject' => ['regularization', $request->id], 'summary' => "Regularization submitted for {$n['date']} (".self::TYPES[$n['type']].')',
                'payload' => ['uuid' => $request->uuid, 'date' => $n['date'], 'type' => $n['type']], 'effective_date' => $n['date']]);
            if ($actor->id !== $this->approvers->userIdOf($employee)) {
                $this->support->notify($this->approvers->userIdOf($employee), 'A regularization was submitted for you', "A regularization for {$n['date']} was submitted on your behalf.", "reg:{$request->uuid}:submitted:self", '/attendance?tab=regularization');
            }

            return $request;
        });

        return [$request->load('employee'), true];
    }

    /** Bulk preview: runs every submission check that does not depend on the employee's own window or monthly limit. Throws the friendly reason. */
    public function dryRun(Employee $employee, array $data): void
    {
        $n = $this->normalize($employee, $data);
        $today = RequestSupport::today($employee);
        if ($n['date'] > $today) {
            throw ValidationException::withMessages(['date' => 'A future date cannot be regularized.']);
        }
        $this->assertJoined($employee, $n['date']);
        if (Regularization::query()->where('employee_id', $employee->id)->where('attendance_date', $n['date'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['date' => 'A regularization is already pending for this date.']);
        }
        $this->support->assertUnlocked($employee->id, $n['date']);
    }

    /** Approve or reject the current step. Approving the last step approves the request and requests recalculation. */
    public function decide(User $actor, Regularization $request, string $decision, ?string $comment): Regularization
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Decision must be approve or reject.');
        $comment = $comment !== null ? trim($comment) : null;
        if ($decision === 'reject' && ($comment === null || $comment === '')) {
            throw ValidationException::withMessages(['comment' => 'Tell the employee why the request is being rejected.']);
        }

        return DB::transaction(function () use ($actor, $request, $decision, $comment) {
            $r = Regularization::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            $employee = Employee::query()->findOrFail($r->employee_id);
            $step = (int) $r->current_step;
            abort_unless($this->approvers->canDecide($actor, $employee, 'regularization', $r->attendance_date, $step), 403, 'You are not an approver for this step of the request.');
            $behalf = $this->approvers->onBehalfOf($actor, $employee, 'regularization', $r->attendance_date, $step);
            $employeeUser = $this->approvers->userIdOf($employee);
            $entity = 'regularization:'.$r->uuid;

            if ($decision === 'reject') {
                $r->update(['status' => 'rejected', 'decided_at' => now()]);
                $this->log($r, $step, 'reject', $actor->id, $behalf, $comment);
                $this->support->completeTasks($entity);
                $this->support->notify($employeeUser, 'Regularization rejected', "Your regularization for {$r->attendance_date} was rejected: $comment", "reg:{$r->uuid}:rejected", '/attendance?tab=regularization');
                $this->events->record('RegularizationRejected', ['employee' => $employee, 'subject' => ['regularization', $r->id], 'summary' => "Regularization for {$r->attendance_date} rejected",
                    'payload' => ['uuid' => $r->uuid, 'date' => $r->attendance_date, 'step' => $step], 'effective_date' => $r->attendance_date]);
                Audit::record('rejected', 'attendance', 'regularization:'.$r->uuid, ['step' => $step, 'on_behalf_of' => $behalf, 'comment' => $comment]);

                return $r->refresh();
            }

            $this->support->assertUnlocked($employee->id, $r->attendance_date);
            $this->log($r, $step, 'approve', $actor->id, $behalf, $comment);
            $this->support->completeTasks($entity);
            if ($step < (int) $r->total_steps) {
                $r->update(['current_step' => $step + 1]);
                $this->openStep($r, $employee);
                Audit::record('step_approved', 'attendance', 'regularization:'.$r->uuid, ['step' => $step, 'on_behalf_of' => $behalf]);

                return $r->refresh();
            }
            $this->finalizeApproval($r, $employee, $actor->id, $step);
            Audit::record('approved', 'attendance', 'regularization:'.$r->uuid, ['step' => $step, 'on_behalf_of' => $behalf, 'comment' => $comment]);

            return $r->refresh();
        });
    }

    /** HR bulk path: an already-approved, single-step regularization created by the bulk runner (the confirmed run is the approval). */
    public function createApproved(User $actor, Employee $employee, array $data, string $batchUuid): Regularization
    {
        $n = $this->normalize($employee, $data);
        $this->support->assertUnlocked($employee->id, $n['date']);

        return DB::transaction(function () use ($actor, $employee, $n, $batchUuid) {
            $r = Regularization::create(['employee_id' => $employee->id, 'attendance_date' => $n['date'], 'type' => $n['type'], 'current_snapshot' => $this->snapshot($employee, $n['date']), 'requested_in' => $n['in'], 'requested_out' => $n['out'],
                'reason' => $n['reason'], 'comments' => $n['comments'] ?? "Bulk run $batchUuid", 'status' => 'pending', 'current_step' => 1, 'total_steps' => 1, 'submitted_by' => $actor->id]);
            $this->log($r, 1, 'submit', $actor->id, null, 'Bulk regularization');
            $this->log($r, 1, 'approve', $actor->id, null, "Bulk run $batchUuid");
            $this->finalizeApproval($r, $employee, $actor->id, 1);
            Audit::record('bulk_regularized', 'attendance', 'regularization:'.$r->uuid, ['batch' => $batchUuid, 'employee' => $employee->uuid, 'date' => $n['date']]);

            return $r->refresh();
        });
    }

    public function withdraw(User $actor, Regularization $request): Regularization
    {
        return DB::transaction(function () use ($actor, $request) {
            $r = Regularization::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            abort_unless($r->submitted_by === $actor->id || $this->support->isSelf($actor, $r->employee_id), 403, 'Only the person who submitted the request can withdraw it.');
            $r->update(['status' => 'withdrawn', 'decided_at' => now()]);
            $this->log($r, (int) $r->current_step, 'withdraw', $actor->id, null, null);
            $this->support->completeTasks('regularization:'.$r->uuid);
            Audit::record('withdrawn', 'attendance', 'regularization:'.$r->uuid);

            return $r->refresh();
        });
    }

    /** Cutoff handling (called by attendance:expire-requests): the request is closed visibly and the employee is told. */
    public function expire(Regularization $request): bool
    {
        return DB::transaction(function () use ($request) {
            $r = Regularization::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                return false;
            }
            $r->update(['status' => 'expired', 'decided_at' => now()]);
            $this->log($r, (int) $r->current_step, 'expire', null, null, 'No decision before the cutoff.');
            $this->support->completeTasks('regularization:'.$r->uuid);
            $employee = Employee::query()->find($r->employee_id);
            $this->support->notify($employee ? $this->approvers->userIdOf($employee) : null, 'Regularization expired', "Your regularization for {$r->attendance_date} expired without a decision. Submit it again if it is still needed, or contact HR.", "reg:{$r->uuid}:expired", '/attendance?tab=regularization');
            Audit::record('expired', 'attendance', 'regularization:'.$r->uuid);

            return true;
        });
    }

    // ---- internals -----------------------------------------------------------------------------

    private function finalizeApproval(Regularization $r, Employee $employee, int $actorId, int $step): void
    {
        $r->update(['status' => 'approved', 'decided_at' => now()]);
        $this->support->completeTasks('missing_punch:'.$employee->id.':'.$r->attendance_date);
        $this->support->notify($this->approvers->userIdOf($employee), 'Regularization approved', "Your regularization for {$r->attendance_date} was approved.", "reg:{$r->uuid}:approved", '/attendance?tab=regularization');
        $this->events->record('RegularizationApproved', ['employee' => $employee, 'subject' => ['regularization', $r->id], 'summary' => "Regularization for {$r->attendance_date} approved",
            'payload' => ['uuid' => $r->uuid, 'date' => $r->attendance_date, 'type' => $r->type], 'effective_date' => $r->attendance_date]);
        $this->support->recalculate($employee->id, $r->attendance_date, 'regularization', $actorId);
    }

    /** Creates the My Work tasks for the request's current step. */
    private function openStep(Regularization $r, Employee $employee): void
    {
        $candidates = $this->approvers->candidates($employee, 'regularization', $r->attendance_date, (int) $r->current_step);
        $name = $employee->person?->display_name ?? $employee->employee_number;
        $due = CarbonImmutable::parse(RequestSupport::today($employee))->addDays((int) RequestRules::setting('cutoffs.manager_approval_days', 2))->toDateString();
        $this->support->assignTasks($candidates, "Approve regularization: $name, {$r->attendance_date}", self::TYPES[$r->type].': '.$r->reason, 'regularization:'.$r->uuid, '/time-office/approvals?kind=regularization', $due, ['kind' => 'regularization', 'uuid' => $r->uuid, 'step' => (int) $r->current_step]);
    }

    private function log(Regularization $r, int $step, string $action, ?int $actorId, ?int $behalf, ?string $comment): void
    {
        RegularizationAction::create(['regularization_id' => $r->id, 'step' => $step, 'action' => $action, 'actor_id' => $actorId, 'on_behalf_of' => $behalf, 'comment' => $comment, 'acted_at' => now()]);
    }

    /** @return array{date:string,type:string,in:?string,out:?string,reason:string,comments:?string} times converted to UTC strings */
    private function normalize(Employee $employee, array $data): array
    {
        $date = $data['date'] ?? null;
        $errors = [];
        if (! LocalInstant::validDate($date)) {
            $errors['date'] = 'Choose the attendance date (YYYY-MM-DD).';
        }
        $type = $data['type'] ?? null;
        if (! isset(self::TYPES[$type])) {
            $errors['type'] = 'Choose a regularization type: '.implode(', ', array_keys(self::TYPES)).'.';
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $errors['reason'] = 'Give a short reason (up to 2000 characters).';
        }
        $comments = isset($data['comments']) ? mb_substr(trim((string) $data['comments']), 0, 2000) : null;
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $zone = $this->zoneFor($employee, $date);
        $rawIn = $data['requested_in'] ?? null;
        $rawOut = $data['requested_out'] ?? null;
        $in = LocalInstant::toUtc($rawIn, $date, $zone, 'requested_in');
        $out = LocalInstant::toUtc($rawOut, $date, $zone, 'requested_out');
        if (! $in && ! $out) {
            throw ValidationException::withMessages(['requested_in' => 'Enter the in time, the out time, or both.']);
        }
        if ($type === 'forgot_in' && ! $in) {
            throw ValidationException::withMessages(['requested_in' => 'Enter the in time you forgot to punch.']);
        }
        if ($type === 'forgot_out' && ! $out) {
            throw ValidationException::withMessages(['requested_out' => 'Enter the out time you forgot to punch.']);
        }
        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            $bare = preg_match('/^\d{1,2}:\d{2}/', trim((string) $rawOut)) === 1;
            if ($bare && $in->diffInMinutes($out->addDay(), true) <= 20 * 60) {
                $out = $out->addDay(); // overnight shift: a bare out time earlier than the in time means the next calendar day
            } else {
                throw ValidationException::withMessages(['requested_out' => 'The out time must be after the in time.']);
            }
        }
        if ($in && $out && $in->diffInMinutes($out, true) > 24 * 60) {
            throw ValidationException::withMessages(['requested_out' => 'The span between in and out cannot exceed 24 hours.']);
        }

        return ['date' => $date, 'type' => $type, 'in' => $in ? LocalInstant::utcString($in) : null, 'out' => $out ? LocalInstant::utcString($out) : null, 'reason' => $reason, 'comments' => $comments !== '' ? $comments : null];
    }

    public function zoneFor(Employee $employee, string $date): string
    {
        return $this->support->zoneFor($employee, $date);
    }

    private function assertJoined(Employee $employee, string $date): void
    {
        $joined = substr((string) $employee->getRawOriginal('joined_on'), 0, 10);
        if ($joined && $date < $joined) {
            throw ValidationException::withMessages(['date' => 'This date is before the employee joined.']);
        }
    }

    private function snapshot(Employee $employee, string $date): ?array
    {
        $day = DB::table('attendance_days')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('attendance_date', $date)->first();

        return $day ? ['status' => $day->status, 'first_in' => RequestSupport::iso($day->first_in), 'last_out' => RequestSupport::iso($day->last_out), 'net_minutes' => (int) $day->net_minutes,
            'late_minutes' => (int) $day->late_minutes, 'missing_punch' => (bool) $day->missing_punch, 'finalization' => $day->finalization] : null;
    }
}
