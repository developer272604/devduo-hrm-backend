<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual adjustments by HR/admin with maker-checker. Raw punches are never modified: an adjustment is a separate, audited row that the
 * processor reads (applied/approved): add_punch {at, direction}, void_punch {event_id}, status_override {status, note}.
 * Reverting never deletes: a new 'reversal' row is written, the original becomes 'reverted', and the whole history stays visible.
 * Sensitive adjustments (bulk, finalized days, dates older than the configured threshold) need a DIFFERENT user holding
 * attendance.adjust.approve before they take effect.
 */
final class AdjustmentService
{
    public const TYPES = ['add_punch', 'void_punch', 'status_override'];

    public function __construct(private RequestSupport $support, private ApproverResolver $approvers, private DomainEvents $events) {}

    public function create(User $actor, Employee $employee, array $data, ?string $batchUuid = null): AttendanceAdjustment
    {
        abort_unless($this->support->scope()->canAccess($actor, $employee, 'attendance.adjust'), 404, 'That employee was not found.');
        $date = $data['date'] ?? null;
        $type = $data['type'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));
        $errors = [];
        if (! LocalInstant::validDate($date)) {
            $errors['date'] = 'Choose the attendance date (YYYY-MM-DD).';
        }
        if (! in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Adjustment type must be one of: '.implode(', ', self::TYPES).'.';
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            $errors['reason'] = 'A reason is required for every manual adjustment.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $day = DB::table('attendance_days')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('attendance_date', $date)->first();
        abort_if($day && $day->finalization === 'locked', 423, "Attendance for $date is locked. Request an unlock before adjusting it.");
        $this->support->assertUnlocked($employee->id, $date);
        [$before, $after] = $this->payload($employee, $type, $date, $data, $day);
        $sensitive = $this->isSensitive($employee, $date, $day, $batchUuid);

        return DB::transaction(function () use ($actor, $employee, $date, $type, $before, $after, $reason, $sensitive, $batchUuid) {
            $adj = AttendanceAdjustment::create(['employee_id' => $employee->id, 'attendance_date' => $date, 'type' => $type, 'before' => $before, 'after' => $after, 'reason' => $reason,
                'status' => $sensitive ? 'pending_approval' : 'applied', 'requires_checker' => $sensitive, 'maker_id' => $actor->id, 'applied_at' => $sensitive ? null : now(), 'batch_uuid' => $batchUuid]);
            Audit::record($sensitive ? 'requested' : 'applied', 'attendance', 'adjustment:'.$adj->uuid, ['employee' => $employee->uuid, 'date' => $date, 'type' => $type, 'reason' => $reason, 'requires_checker' => $sensitive, 'batch' => $batchUuid]);
            $sensitive ? $this->requestCheck($adj, $employee) : $this->applied($adj, $employee, $actor->id);

            return $adj->load('employee');
        });
    }

    public function approve(User $checker, AttendanceAdjustment $adjustment, ?string $comment = null): AttendanceAdjustment
    {
        return DB::transaction(function () use ($checker, $adjustment, $comment) {
            $adj = $this->pending($adjustment);
            abort_if($checker->id === $adj->maker_id, 403, 'A different person must approve a sensitive adjustment (maker-checker). Ask another approver to review it.');
            $employee = Employee::query()->findOrFail($adj->employee_id);
            abort_unless($this->support->scope()->canAccess($checker, $employee, 'attendance.adjust.approve'), 404, 'That adjustment was not found.');
            $this->support->assertUnlocked($employee->id, $adj->attendance_date);
            $adj->update(['status' => 'applied', 'checker_id' => $checker->id, 'checked_at' => now(), 'checker_comment' => $comment, 'applied_at' => now()]);
            $this->support->completeTasks('adjustment:'.$adj->uuid);
            $this->support->notify($adj->maker_id, 'Adjustment approved', "Your adjustment for {$adj->attendance_date} was approved and applied.", "adj:{$adj->uuid}:approved", '/time-office/approvals?kind=adjustment');
            $this->applied($adj, $employee, $checker->id);
            Audit::record('approved', 'attendance', 'adjustment:'.$adj->uuid, ['maker' => $adj->maker_id, 'checker' => $checker->id, 'comment' => $comment]);

            return $adj->refresh();
        });
    }

    public function reject(User $checker, AttendanceAdjustment $adjustment, ?string $comment): AttendanceAdjustment
    {
        $comment = $comment !== null ? trim($comment) : '';
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Tell the maker why the adjustment is being rejected.']);
        }

        return DB::transaction(function () use ($checker, $adjustment, $comment) {
            $adj = $this->pending($adjustment);
            abort_if($checker->id === $adj->maker_id, 403, 'A different person must review a sensitive adjustment (maker-checker).');
            $employee = Employee::query()->findOrFail($adj->employee_id);
            abort_unless($this->support->scope()->canAccess($checker, $employee, 'attendance.adjust.approve'), 404, 'That adjustment was not found.');
            $adj->update(['status' => 'rejected', 'checker_id' => $checker->id, 'checked_at' => now(), 'checker_comment' => $comment]);
            $this->support->completeTasks('adjustment:'.$adj->uuid);
            $this->support->notify($adj->maker_id, 'Adjustment rejected', "Your adjustment for {$adj->attendance_date} was rejected: $comment", "adj:{$adj->uuid}:rejected", '/time-office/approvals?kind=adjustment');
            Audit::record('rejected', 'attendance', 'adjustment:'.$adj->uuid, ['checker' => $checker->id, 'comment' => $comment]);

            return $adj->refresh();
        });
    }

    /** Writes a reversing adjustment; the original stays in history as 'reverted' once the reversal takes effect. */
    public function revert(User $actor, AttendanceAdjustment $adjustment, string $reason): AttendanceAdjustment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to revert an adjustment.']);
        }
        $employee = Employee::query()->findOrFail($adjustment->employee_id);
        abort_unless($this->support->scope()->canAccess($actor, $employee, 'attendance.adjust'), 404, 'That adjustment was not found.');

        return DB::transaction(function () use ($actor, $adjustment, $employee, $reason) {
            $orig = AttendanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($orig->status, ['applied', 'approved'], true) && $orig->type !== 'reversal', 422, 'Only an applied adjustment can be reverted.');
            abort_if(AttendanceAdjustment::query()->where('type', 'reversal')->where('status', 'pending_approval')->where('after->reverses', $orig->uuid)->exists(), 422, 'A reversal for this adjustment is already waiting for approval.');
            $this->support->assertUnlocked($employee->id, $orig->attendance_date);
            $day = DB::table('attendance_days')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('attendance_date', $orig->attendance_date)->first();
            abort_if($day && $day->finalization === 'locked', 423, "Attendance for {$orig->attendance_date} is locked. Request an unlock before reverting.");
            $sensitive = $orig->requires_checker || $this->isSensitive($employee, $orig->attendance_date, $day, null);
            $rev = AttendanceAdjustment::create(['employee_id' => $employee->id, 'attendance_date' => $orig->attendance_date, 'type' => 'reversal', 'before' => $orig->after,
                'after' => ['reverses' => $orig->uuid, 'original_type' => $orig->type, 'restored' => $orig->before], 'reason' => $reason, 'status' => $sensitive ? 'pending_approval' : 'applied',
                'requires_checker' => $sensitive, 'maker_id' => $actor->id, 'applied_at' => $sensitive ? null : now()]);
            Audit::record('revert_requested', 'attendance', 'adjustment:'.$rev->uuid, ['reverses' => $orig->uuid, 'reason' => $reason, 'requires_checker' => $sensitive]);
            if ($sensitive) {
                $this->requestCheck($rev, $employee);
            } else {
                $this->applied($rev, $employee, $actor->id);
            }

            return $rev->load('employee');
        });
    }

    public function isSensitive(Employee $employee, string $date, ?object $day, ?string $batchUuid): bool
    {
        if ($batchUuid) {
            return true;
        }
        if ($day && in_array($day->finalization, ['approved', 'locked'], true)) {
            return true;
        }
        $daysBack = (int) AttendanceSettings::get('adjustments.sensitive_days_back', 7);

        return CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse(RequestSupport::today($employee)), false) > $daysBack;
    }

    // ---- internals -----------------------------------------------------------------------------

    private function pending(AttendanceAdjustment $adjustment): AttendanceAdjustment
    {
        $adj = AttendanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
        abort_unless($adj->status === 'pending_approval', 409, "This adjustment is already {$adj->status}.");

        return $adj;
    }

    private function requestCheck(AttendanceAdjustment $adj, Employee $employee): void
    {
        $checkers = collect($this->approvers->hrUsers('adjustment'))->reject(fn ($id) => $id === $adj->maker_id)->values();
        $name = $employee->person?->display_name ?? $employee->employee_number;
        $this->support->assignTasks($checkers, "Review attendance adjustment: $name, {$adj->attendance_date}", ucfirst(str_replace('_', ' ', $adj->type)).': '.$adj->reason, 'adjustment:'.$adj->uuid, '/time-office/approvals?kind=adjustment', null, ['kind' => 'adjustment', 'uuid' => $adj->uuid]);
    }

    /** Effects of an adjustment that is now in force. */
    private function applied(AttendanceAdjustment $adj, Employee $employee, int $actorId): void
    {
        if ($adj->type === 'reversal' && ! empty($adj->after['reverses'])) {
            AttendanceAdjustment::query()->where('uuid', $adj->after['reverses'])->update(['status' => 'reverted']);
        }
        $this->events->record('AttendanceAdjusted', ['employee' => $employee, 'subject' => ['attendance_adjustment', $adj->id], 'summary' => 'Attendance adjusted for '.$adj->attendance_date.' ('.str_replace('_', ' ', $adj->type).')',
            'payload' => ['uuid' => $adj->uuid, 'type' => $adj->type, 'date' => $adj->attendance_date, 'maker_id' => $adj->maker_id, 'checker_id' => $adj->checker_id, 'batch' => $adj->batch_uuid], 'effective_date' => $adj->attendance_date, 'actor_id' => $actorId]);
        $this->support->recalculate($employee->id, $adj->attendance_date, 'manual', $actorId);
    }

    /** @return array{0:array,1:array} before and after payloads in the shape the processor reads */
    private function payload(Employee $employee, string $type, string $date, array $data, ?object $day): array
    {
        $dayBefore = $day ? ['status' => $day->status, 'first_in' => RequestSupport::iso($day->first_in), 'last_out' => RequestSupport::iso($day->last_out), 'net_minutes' => (int) $day->net_minutes] : ['status' => null];
        if ($type === 'status_override') {
            $status = $data['status'] ?? null;
            if (! in_array($status, AttendanceStatus::ALL, true)) {
                throw ValidationException::withMessages(['status' => 'Choose a valid attendance status: '.implode(', ', AttendanceStatus::ALL).'.']);
            }

            return [$dayBefore, ['status' => $status, 'note' => mb_substr(trim((string) ($data['note'] ?? $data['reason'] ?? '')), 0, 500)]];
        }
        if ($type === 'add_punch') {
            $direction = strtoupper((string) ($data['direction'] ?? ''));
            if (! in_array($direction, ['IN', 'OUT'], true)) {
                throw ValidationException::withMessages(['direction' => 'Direction must be IN or OUT.']);
            }
            $raw = trim((string) ($data['at'] ?? ''));
            try {
                $at = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw) ? CarbonImmutable::parse($raw)->utc() : LocalInstant::toUtc($raw, $date, $this->support->zoneFor($employee, $date), 'at');
            } catch (\Carbon\Exceptions\InvalidFormatException) {
                $at = null;
            }
            if (! $at) {
                throw ValidationException::withMessages(['at' => 'Enter the punch time, for example 2026-10-06T09:30:00+05:30 or 09:30 (employee local time).']);
            }

            return [$dayBefore, ['at' => $at->toIso8601ZuluString(), 'direction' => $direction]];
        }
        $event = DB::table('attendance_events')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employee->id)->where('uuid', (string) ($data['event_uuid'] ?? ''))->first();
        if (! $event) {
            throw ValidationException::withMessages(['event_uuid' => 'That punch was not found for this employee.']);
        }
        if ($event->voided_at) {
            throw ValidationException::withMessages(['event_uuid' => 'That punch is already voided.']);
        }

        return [['event_id' => (int) $event->id, 'punched_at' => RequestSupport::iso($event->punched_at), 'direction' => $event->direction, 'source' => $event->source], ['event_id' => (int) $event->id]];
    }
}
