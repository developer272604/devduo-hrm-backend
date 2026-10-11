<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Models\OnDutyRequest;
use App\Domain\Attendance\Support\TimeZones;
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
 * On-duty and remote-work requests. Approved rows are read by the processor (type remote_work = remote day, the rest on_duty).
 * Remote work is not leave: it never touches leave balances, and a day fully covered by approved leave cannot also be on duty.
 */
final class OnDutyService
{
    public const TYPES = ['client_visit' => 'Client visit', 'field_work' => 'Field work', 'training' => 'Training', 'official_travel' => 'Official travel', 'remote_work' => 'Remote work'];

    public const MAX_DAYS = 31;

    public function __construct(private RequestSupport $support, private ApproverResolver $approvers, private DomainEvents $events) {}

    /** @return array{0:OnDutyRequest,1:bool} */
    public function submit(User $actor, Employee $employee, array $data, ?UploadedFile $file = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = OnDutyRequest::query()->where('idempotency_key', $idempotencyKey)->first())) {
            abort_unless($existing->employee_id === $employee->id, 409, 'That idempotency key was already used for a different request.');

            return [$existing, false];
        }
        $initiatedByManager = ! $this->support->isSelf($actor, $employee);
        abort_unless(! $initiatedByManager || $this->support->scope()->canAccess($actor, $employee, 'attendance.approve'), 404, 'That employee was not found.');
        $n = $this->normalize($employee, $data);
        $remote = RequestRules::remote($employee, $n['start_date']);
        if ($n['type'] === 'remote_work' && ! ($remote['allowed'] ?? true)) {
            throw ValidationException::withMessages(['type' => 'Remote work is not allowed by your attendance policy.']);
        }
        $this->assertWindow($employee, $n);
        $this->assertNoConflicts($employee, $n);
        $exclude = $initiatedByManager ? [$actor->id] : [];
        $auto = $n['type'] === 'remote_work' && ! ($remote['requires_approval'] ?? true);
        $approver = $auto ? null : $this->approvers->forEmployee($employee, 'on_duty', $n['start_date'], 1, $exclude);
        abort_unless($auto || $approver, 422, 'No approver is available for this request. Please ask HR to assign a reporting manager.');

        $uuid = (string) Str::uuid();
        $attachment = $file ? $this->support->storeAttachment($file, 'on-duty', $uuid) : null;

        return [DB::transaction(function () use ($actor, $employee, $n, $uuid, $attachment, $idempotencyKey, $approver, $auto, $exclude) {
            $r = OnDutyRequest::create(['uuid' => $uuid, 'employee_id' => $employee->id, 'type' => $n['type'], 'starts_at' => $n['starts_at'], 'ends_at' => $n['ends_at'], 'timezone' => $n['zone'],
                'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'full_day' => $n['full_day'], 'reason' => $n['reason'], 'location_text' => $n['location_text'], 'attachment_path' => $attachment['path'] ?? null,
                'status' => 'pending', 'requested_by' => $actor->id, 'approver_user_id' => $approver?->id, 'idempotency_key' => $idempotencyKey]);
            Audit::record('requested', 'attendance', 'on_duty:'.$r->uuid, ['employee' => $employee->uuid, 'type' => $n['type'], 'from' => $n['start_date'], 'to' => $n['end_date']]);
            $this->events->record('OnDutyRequested', ['employee' => $employee, 'subject' => ['on_duty_request', $r->id], 'summary' => self::TYPES[$n['type']]." requested for {$n['start_date']}",
                'payload' => ['uuid' => $r->uuid, 'type' => $n['type'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date']], 'effective_date' => $n['start_date']]);
            if ($auto) {
                $this->approveRow($r, $employee, null, 'Auto-approved by attendance policy (remote work needs no approval).');
            } else {
                $name = $employee->person?->display_name ?? $employee->employee_number;
                $due = CarbonImmutable::parse(RequestSupport::today($employee))->addDays((int) RequestRules::setting('cutoffs.manager_approval_days', 2))->toDateString();
                $this->support->assignTasks($this->approvers->candidates($employee, 'on_duty', $n['start_date'], 1, $exclude), "Approve {$this->label($r)}: $name, {$n['start_date']}", $n['reason'], 'on_duty:'.$r->uuid,
                    '/time-office/approvals?kind=on_duty', $due, ['kind' => 'on_duty', 'uuid' => $r->uuid]);
            }

            return $r->refresh()->load('employee');
        }), true];
    }

    public function decide(User $actor, OnDutyRequest $request, string $decision, ?string $comment): OnDutyRequest
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Decision must be approve or reject.');
        $comment = $comment !== null ? trim($comment) : null;
        if ($decision === 'reject' && ($comment === null || $comment === '')) {
            throw ValidationException::withMessages(['comment' => 'Tell the requester why the request is being rejected.']);
        }

        return DB::transaction(function () use ($actor, $request, $decision, $comment) {
            $r = OnDutyRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            $employee = Employee::query()->findOrFail($r->employee_id);
            $exclude = $r->requested_by !== $this->approvers->userIdOf($employee) ? [$r->requested_by] : [];
            abort_unless($this->approvers->canDecide($actor, $employee, 'on_duty', $r->start_date, 1, $exclude), 403, 'You are not an approver for this request.');
            $entity = 'on_duty:'.$r->uuid;
            if ($decision === 'reject') {
                $r->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment]);
                $this->support->completeTasks($entity);
                foreach (array_unique(array_filter([$this->approvers->userIdOf($employee), $r->requested_by])) as $uid) {
                    $this->support->notify($uid, $this->label($r).' rejected', "Your {$this->label($r)} request for {$r->start_date} was rejected: $comment", "od:{$r->uuid}:rejected", '/attendance?tab=on-duty');
                }
                $this->events->record('OnDutyRejected', ['employee' => $employee, 'subject' => ['on_duty_request', $r->id], 'summary' => "{$this->label($r)} for {$r->start_date} rejected", 'payload' => ['uuid' => $r->uuid], 'effective_date' => $r->start_date]);
                Audit::record('rejected', 'attendance', $entity, ['comment' => $comment]);

                return $r->refresh();
            }
            foreach ($this->dates($r->start_date, $r->end_date) as $date) {
                $this->support->assertUnlocked($employee->id, $date);
            }
            $this->approveRow($r, $employee, $actor, $comment);

            return $r->refresh();
        });
    }

    public function withdraw(User $actor, OnDutyRequest $request): OnDutyRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $r = OnDutyRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            abort_unless($r->requested_by === $actor->id || $this->support->isSelf($actor, $r->employee_id), 403, 'Only the person who raised the request can withdraw it.');
            $r->update(['status' => 'withdrawn', 'decided_at' => now()]);
            $this->support->completeTasks('on_duty:'.$r->uuid);
            Audit::record('withdrawn', 'attendance', 'on_duty:'.$r->uuid);

            return $r->refresh();
        });
    }

    public function expire(OnDutyRequest $request): bool
    {
        return DB::transaction(function () use ($request) {
            $r = OnDutyRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                return false;
            }
            $r->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_comment' => 'Closed automatically: no decision before the cutoff.']);
            $this->support->completeTasks('on_duty:'.$r->uuid);
            $this->support->notify($r->requested_by, $this->label($r).' request closed', "Your {$this->label($r)} request for {$r->start_date} had no decision before the cutoff and was closed.", "od:{$r->uuid}:expired", '/attendance?tab=on-duty');
            Audit::record('expired', 'attendance', 'on_duty:'.$r->uuid);

            return true;
        });
    }

    /** Bulk path: a full-day approved on-duty record for one date (the confirmed bulk run is the approval). */
    public function createApproved(User $actor, Employee $employee, array $data, string $batchUuid): OnDutyRequest
    {
        $n = $this->normalize($employee, ['type' => $data['type'], 'start_date' => $data['date'], 'reason' => $data['reason'], 'location_text' => $data['location_text'] ?? null]);
        $this->assertNoConflicts($employee, $n);
        $this->support->assertUnlocked($employee->id, $n['start_date']);

        return DB::transaction(function () use ($actor, $employee, $n, $batchUuid) {
            $r = OnDutyRequest::create(['employee_id' => $employee->id, 'type' => $n['type'], 'starts_at' => $n['starts_at'], 'ends_at' => $n['ends_at'], 'timezone' => $n['zone'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'],
                'full_day' => true, 'reason' => $n['reason'], 'location_text' => $n['location_text'], 'status' => 'pending', 'requested_by' => $actor->id]);
            $this->approveRow($r, $employee, $actor, "Bulk run $batchUuid");
            Audit::record('bulk_marked', 'attendance', 'on_duty:'.$r->uuid, ['batch' => $batchUuid, 'employee' => $employee->uuid, 'date' => $n['start_date']]);

            return $r->refresh();
        });
    }

    /** Dry validation for the bulk preview: throws ValidationException with the friendly reason. */
    public function validateDay(Employee $employee, string $type, string $date, string $reason): void
    {
        $n = $this->normalize($employee, ['type' => $type, 'start_date' => $date, 'reason' => $reason]);
        $this->assertNoConflicts($employee, $n);
    }

    // ---- internals -----------------------------------------------------------------------------

    private function approveRow(OnDutyRequest $r, Employee $employee, ?User $actor, ?string $comment): void
    {
        $r->update(['status' => 'approved', 'decided_by' => $actor?->id, 'decided_at' => now(), 'decision_comment' => $comment]);
        $this->support->completeTasks('on_duty:'.$r->uuid);
        foreach (array_unique(array_filter([$this->approvers->userIdOf($employee), $r->requested_by])) as $uid) {
            $this->support->notify($uid, $this->label($r).' approved', "{$this->label($r)} for {$r->start_date} was approved.", "od:{$r->uuid}:approved", '/attendance?tab=on-duty');
        }
        $this->events->record('OnDutyApproved', ['employee' => $employee, 'subject' => ['on_duty_request', $r->id], 'summary' => "{$this->label($r)} for {$r->start_date} approved",
            'payload' => ['uuid' => $r->uuid, 'type' => $r->type, 'start_date' => $r->start_date, 'end_date' => $r->end_date], 'effective_date' => $r->start_date]);
        foreach ($this->dates($r->start_date, $r->end_date) as $date) {
            $this->support->recalculate($employee->id, $date, 'regularization', $actor?->id);
        }
        Audit::record('approved', 'attendance', 'on_duty:'.$r->uuid, ['comment' => $comment]);
    }

    private function label(OnDutyRequest $r): string
    {
        return self::TYPES[$r->type] ?? 'On duty';
    }

    /** @return array<int,string> every local date from..to */
    private function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to && count($out) <= self::MAX_DAYS; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    private function normalize(Employee $employee, array $data): array
    {
        $errors = [];
        $type = $data['type'] ?? null;
        if (! isset(self::TYPES[$type])) {
            $errors['type'] = 'Choose a type: '.implode(', ', array_keys(self::TYPES)).'.';
        }
        $start = $data['start_date'] ?? null;
        $end = $data['end_date'] ?? $start;
        if (! LocalInstant::validDate($start)) {
            $errors['start_date'] = 'Choose the start date (YYYY-MM-DD).';
        }
        if (! LocalInstant::validDate($end)) {
            $errors['end_date'] = 'Choose the end date (YYYY-MM-DD).';
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $errors['reason'] = 'Give a short reason (up to 2000 characters).';
        }
        $zone = $data['timezone'] ?? $employee->timezone ?: 'UTC';
        if (! TimeZones::isValid($zone)) {
            $errors['timezone'] = 'That time zone is not recognised.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if ($end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }
        if (count($this->dates($start, $end)) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'A request can cover at most '.self::MAX_DAYS.' days. Split longer periods into several requests.']);
        }
        $full = (bool) ($data['full_day'] ?? true);
        if ($full) {
            $startsAt = TimeZones::localToUtc($start, '00:00', $zone);
            $endsAt = TimeZones::localToUtc($end, '23:59:59', $zone);
        } else {
            if ($start !== $end) {
                throw ValidationException::withMessages(['end_date' => 'A part-day request must start and end on the same date.']);
            }
            $startsAt = LocalInstant::toUtc($data['start_time'] ?? null, $start, $zone, 'start_time');
            $endsAt = LocalInstant::toUtc($data['end_time'] ?? null, $start, $zone, 'end_time');
            if (! $startsAt || ! $endsAt) {
                throw ValidationException::withMessages(['start_time' => 'Enter the start and end time for a part-day request.']);
            }
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time.']);
            }
        }

        return ['type' => $type, 'start_date' => $start, 'end_date' => $end, 'full_day' => $full, 'zone' => $zone, 'starts_at' => LocalInstant::utcString($startsAt), 'ends_at' => LocalInstant::utcString($endsAt),
            'reason' => $reason, 'location_text' => isset($data['location_text']) && trim((string) $data['location_text']) !== '' ? mb_substr(trim((string) $data['location_text']), 0, 250) : null];
    }

    private function assertWindow(Employee $employee, array $n): void
    {
        $today = RequestSupport::today($employee);
        $window = RequestRules::regularization($employee, $n['start_date'])['window_days'];
        if ($n['start_date'] < CarbonImmutable::parse($today)->subDays($window)->toDateString()) {
            throw ValidationException::withMessages(['start_date' => "Requests can be raised for the last $window days. For older dates ask HR for a manual adjustment."]);
        }
        if ($n['start_date'] > CarbonImmutable::parse($today)->addDays(180)->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'Requests can be raised up to 180 days ahead.']);
        }
    }

    private function assertNoConflicts(Employee $employee, array $n): void
    {
        $overlap = OnDutyRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->where('starts_at', '<', $n['ends_at'])->where('ends_at', '>', $n['starts_at'])->first();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'This overlaps your '.strtolower(self::TYPES[$overlap->type]).' request for '.$overlap->start_date.($overlap->end_date !== $overlap->start_date ? ' to '.$overlap->end_date : '').' ('.$overlap->status.').']);
        }
        if (app()->bound(LeaveDataProvider::class)) {
            $coverage = app(LeaveDataProvider::class)->coverageForRange([$employee->id], $n['start_date'], $n['end_date']);
            foreach ($coverage as $key => $c) {
                if (($c['portion'] ?? null) === 'full_day') {
                    throw ValidationException::withMessages(['start_date' => 'You are on approved leave on '.substr((string) $key, strpos((string) $key, '|') + 1).'. A day of leave cannot also be on duty; cancel the leave first.']);
                }
            }
        }
        foreach ($this->dates($n['start_date'], $n['end_date']) as $date) {
            $this->support->assertUnlocked($employee->id, $date);
        }
    }
}
