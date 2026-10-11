<?php

namespace App\Domain\Leave\CompOff;

use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerRejected;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveCompOffClaim;
use App\Domain\Leave\Models\LeaveCompOffCredit;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Requests\AccountLocator;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestRejected;
use App\Domain\Leave\Requests\RequestService;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compensatory leave. Credits are EARNED from approved overtime / rest-day or holiday work (or granted by an authorized user),
 * approved, then posted to the ledger as a credit lot with a validity end (`expires_on`). Claims spend a credit through an
 * ordinary leave request, so the source-to-use trace is: credit -> ledger lot -> allocation on the consumption -> request.
 */
final class CompOffService
{
    public const SOURCES = ['overtime', 'holiday_work', 'rest_day_work', 'authorized'];

    public function __construct(private LedgerService $ledger, private LeaveSupport $support, private RequestService $requests, private DomainEvents $events, private AccountLocator $accounts) {}

    /** The comp-off leave type: the given uuid, else the tenant's active type of kind comp_off. */
    public function type(?string $uuid = null): LeaveType
    {
        $type = $uuid ? LeaveType::query()->where('uuid', $uuid)->where('active', true)->first() : LeaveType::query()->where('kind', 'comp_off')->where('active', true)->orderBy('id')->first();
        if (! $type) {
            throw ValidationException::withMessages(['leave_type' => 'No comp-off leave type is configured. Ask HR to create one.']);
        }

        return $type;
    }

    /** Employee (or manager on their behalf) asks for a credit. Overtime-based credits derive their units from the APPROVED overtime. */
    public function earn(User $actor, Employee $employee, array $in): LeaveCompOffCredit
    {
        $source = $in['source_type'] ?? 'overtime';
        if (! in_array($source, self::SOURCES, true)) {
            throw ValidationException::withMessages(['source_type' => 'Source must be one of: '.implode(', ', self::SOURCES).'.']);
        }
        $type = $this->type($in['leave_type'] ?? null);
        $sourceId = $in['source_id'] ?? null;
        $workDate = $in['work_date'] ?? null;
        $units = isset($in['units']) ? $in['units'] : null;
        if ($source === 'overtime' || $sourceId && in_array($source, ['holiday_work', 'rest_day_work'], true)) {
            $ot = OvertimeRequest::query()->where('uuid', (string) $sourceId)->where('employee_id', $employee->id)->first();
            if (! $ot || ! in_array($ot->status, ['approved', 'partially_approved'], true) || ! $ot->approved_minutes) {
                throw ValidationException::withMessages(['source_id' => 'Choose an approved overtime request of this employee to earn comp-off from.']);
            }
            $workDate = $ot->work_date;
            $units = Units::div((string) $ot->approved_minutes, (string) LeaveSettings::get('units.minutes_per_day', 480));
            $source = $ot->ot_type === 'holiday' ? 'holiday_work' : (in_array($ot->ot_type, ['weekly_off', 'rest_day'], true) ? 'rest_day_work' : 'overtime');
        } elseif ($source === 'authorized') {
            abort_unless($actor->allows('leave.compoff.manage') && $this->support->scope()->canAccess($actor, $employee, 'leave.compoff.manage'), 403, 'Only an authorized person can grant comp-off directly.');
        }
        if (! $workDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $workDate)) {
            throw ValidationException::withMessages(['work_date' => 'Enter the date worked (YYYY-MM-DD).']);
        }
        try {
            $units = Units::strict((string) $units);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['units' => 'Enter the comp-off in days with at most 4 decimals.']);
        }
        if (Units::cmp($units, '0') <= 0) {
            throw ValidationException::withMessages(['units' => 'Comp-off must be more than zero.']);
        }
        if ($sourceId && LeaveCompOffCredit::query()->where('employee_id', $employee->id)->where('source_type', $source)->where('source_id', $sourceId)->exists()) {
            throw ValidationException::withMessages(['source_id' => 'Comp-off was already requested for that work.']);
        }
        $credit = DB::transaction(function () use ($actor, $employee, $type, $source, $sourceId, $workDate, $units, $in) {
            $credit = LeaveCompOffCredit::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'source_type' => $source, 'source_id' => $sourceId, 'work_date' => $workDate, 'units' => $units,
                'status' => 'pending', 'note' => $in['note'] ?? null, 'requested_by' => $actor->id]);
            Audit::record('requested', 'leave', 'compoff:'.$credit->uuid, ['employee' => $employee->uuid, 'source' => $source, 'units' => $units]);

            return $credit;
        });
        $auto = ! LeaveSettings::get('comp_off.require_approval', true) || $source === 'authorized';
        if ($auto) {
            return $this->decide($actor, $credit, 'approve', null, true);
        }
        $name = $employee->person?->display_name ?? $employee->employee_number;
        $approvers = app(\App\Domain\Leave\Requests\LeaveApprovers::class)->forRole($employee, $workDate, 'manager', [$actor->id]);
        $this->support->assignTasks($approvers, "Approve comp-off: $name, work on $workDate ($units day)", 'Comp-off credit waiting for approval.', 'compoff:'.$credit->uuid, '/leave/approvals?compoff='.$credit->uuid);

        return $credit;
    }

    public function decide(User $actor, LeaveCompOffCredit $credit, string $decision, ?string $comment = null, bool $trusted = false): LeaveCompOffCredit
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Decision must be approve or reject.');

        return DB::transaction(function () use ($actor, $credit, $decision, $comment, $trusted) {
            $c = LeaveCompOffCredit::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->status === 'pending', 409, "This comp-off request was already {$c->status}.");
            $employee = Employee::query()->findOrFail($c->employee_id);
            if (! $trusted) {
                $approvers = app(\App\Domain\Leave\Requests\LeaveApprovers::class);
                $mayManage = $actor->allows('leave.compoff.manage') && $this->support->scope()->canAccess($actor, $employee, 'leave.compoff.manage');
                $mayDecide = $actor->allows('leave.approve') && $approvers->forRole($employee, $c->work_date, 'manager')->contains('id', $actor->id);
                abort_unless(($mayManage || $mayDecide) && $approvers->userIdOf($employee) !== $actor->id, 403, 'You cannot decide this comp-off request.');
            }
            $entity = 'compoff:'.$c->uuid;
            if ($decision === 'reject') {
                $c->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'note' => trim(($c->note ? $c->note."\n" : '').'Rejected: '.($comment ?? ''))]);
                $this->support->completeTasks($entity);
                $this->support->notify($this->userOf($employee), 'Comp-off rejected', "Your comp-off for {$c->work_date} was rejected.", $entity.':rejected', '/leave/my');
                Audit::record('rejected', 'leave', $entity);

                return $c->refresh();
            }
            $validity = (int) LeaveSettings::get('comp_off.default_validity_days', 90);
            $from = max($c->work_date, TenantClock::today($employee->timezone ?: null)) === $c->work_date ? $c->work_date : $c->work_date;
            $expires = CarbonImmutable::parse($c->work_date)->addDays($validity)->toDateString();
            $type = LeaveType::query()->findOrFail($c->leave_type_id);
            $account = app()->bound(\App\Domain\Leave\Accrual\AccountService::class) ? app(\App\Domain\Leave\Accrual\AccountService::class)->ensure($employee->id, $type->id, $from) : $this->accounts->forDate($employee->id, $type->id, $from);
            if (! $account) {
                throw new RequestRejected('No open comp-off balance exists for '.$from.'. Ask HR to open the account.', [], 422);
            }
            try {
                $entry = $this->ledger->post(new LedgerPosting($account->id, 'comp_off_credit', 'accrued', $c->getRawOriginal('units'), $from, 'compoff:'.$c->uuid.':credit', expiresOn: $expires, compOffCreditId: $c->id,
                    referenceType: 'comp_off', referenceId: $c->uuid, reason: 'Comp-off earned for work on '.$c->work_date, meta: ['source' => $c->source_type, 'source_id' => $c->source_id, 'work_date' => $c->work_date, 'validity_days' => $validity], createdBy: $actor->id))->entry;
            } catch (LedgerRejected $e) {
                throw new RequestRejected($e->getMessage(), [], $e->getStatusCode());
            }
            $c->update(['status' => 'approved', 'valid_from' => $from, 'expires_on' => $expires, 'decided_by' => $actor->id, 'decided_at' => now(), 'ledger_entry_id' => $entry->id]);
            $this->support->completeTasks($entity);
            $this->support->notify($this->userOf($employee), 'Comp-off granted', rtrim(rtrim($c->getRawOriginal('units'), '0'), '.')." day(s) of comp-off, valid until $expires.", $entity.':approved', '/leave/my');
            $this->events->record(LeaveEvents::COMP_OFF_GRANTED, ['employee' => $employee, 'subject' => ['leave_comp_off_credit', $c->id], 'summary' => 'Comp-off granted', 'actor_id' => $actor->id, 'payload' => ['uuid' => $c->uuid, 'expires_on' => $expires], 'effective_date' => $from]);
            Audit::record('approved', 'leave', $entity, ['units' => $c->getRawOriginal('units')]);

            return $c->refresh();
        });
    }

    /** Spend a credit: submits an ordinary comp-off leave request and links the claim. @return array{0:LeaveRequest,1:LeaveCompOffClaim} */
    public function claim(User $actor, Employee $employee, LeaveCompOffCredit $credit, array $in, ?string $idempotencyKey = null): array
    {
        abort_unless($credit->employee_id === $employee->id && $credit->status === 'approved', 422, 'Only an approved comp-off credit of this employee can be claimed.');
        $type = LeaveType::query()->findOrFail($credit->leave_type_id);
        if ($credit->expires_on && ($in['end_date'] ?? $in['start_date'] ?? '') > $credit->expires_on) {
            throw ValidationException::withMessages(['start_date' => 'This comp-off is valid until '.$credit->expires_on.'; choose earlier dates.']);
        }
        [$request] = $this->requests->submit($actor, $employee, ['leave_type' => $type->uuid] + $in, $idempotencyKey);
        $units = Units::normalize($request->getRawOriginal('charged_units'));
        $left = $this->remaining($credit);
        if (Units::cmp($units, $left) > 0) {
            $this->requests->withdraw($actor, $request, 'More than the credit that is left');
            throw ValidationException::withMessages(['units' => "This comp-off credit has only $left day(s) left; the request needs $units."]);
        }
        $claim = LeaveCompOffClaim::query()->firstOrCreate(['request_id' => $request->id], ['employee_id' => $employee->id, 'credit_id' => $credit->id, 'units' => $units, 'status' => 'pending']);

        return [$request, $claim];
    }

    public function remaining(LeaveCompOffCredit $credit): string
    {
        $used = LeaveCompOffClaim::query()->where('credit_id', $credit->id)->whereIn('request_id', fn ($q) => $q->select('id')->from('leave_requests')->whereIn('status', ['pending', 'approved', 'partially_cancelled']))->get()->map(fn ($c) => $c->getRawOriginal('units'));

        return Units::sub($credit->getRawOriginal('units'), Units::sum($used));
    }

    /** Claim status mirrors its leave request. */
    public function claimStatus(LeaveCompOffClaim $claim): string
    {
        $status = LeaveRequest::query()->whereKey($claim->request_id)->value('status');

        return match ($status) {
            'approved', 'partially_cancelled' => 'approved', 'rejected' => 'rejected', 'pending' => 'pending', default => 'cancelled',
        };
    }

    /** Marks approved credits whose validity ended (the ledger lot itself is expired by the expiry engine). */
    public function expireDue(): int
    {
        return LeaveCompOffCredit::query()->where('status', 'approved')->where('expires_on', '<', TenantClock::today())->update(['status' => 'expired']);
    }

    /** Source-to-use trace of a credit. @return array<string,mixed> */
    public function trace(LeaveCompOffCredit $credit): array
    {
        $uses = $credit->ledger_entry_id ? DB::table('leave_ledger_allocations as a')->join('leave_ledger_entries as e', 'e.id', '=', 'a.entry_id')->leftJoin('leave_requests as r', 'r.id', '=', 'e.request_id')
            ->where('a.source_entry_id', $credit->ledger_entry_id)->get(['r.uuid as request', 'r.status', 'e.effective_date', 'a.units', 'e.id as entry_id']) : collect();
        $reversed = $uses->pluck('entry_id')->filter(fn ($id) => DB::table('leave_ledger_entries')->where('reverses_entry_id', $id)->exists())->all();

        return ['credit' => $credit->uuid, 'source' => ['type' => $credit->source_type, 'id' => $credit->source_id, 'work_date' => $credit->work_date], 'units' => Units::normalize($credit->getRawOriginal('units')), 'expires_on' => $credit->expires_on,
            'uses' => $uses->reject(fn ($u) => in_array($u->entry_id, $reversed))->map(fn ($u) => ['request' => $u->request, 'status' => $u->status, 'date' => substr($u->effective_date, 0, 10), 'units' => Units::normalize($u->units)])->values()->all()];
    }

    private function userOf(Employee $employee): ?int
    {
        return app(\App\Domain\Leave\Requests\LeaveApprovers::class)->userIdOf($employee);
    }
}
