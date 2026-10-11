<?php

namespace App\Domain\Leave\Adjustments;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Manual balance changes with maker-checker. A request (leave.adjust) only records intent; nothing touches the ledger until a
 * different person holding leave.adjust.approve approves it (the setting balance.maker_checker=false lets one person do both).
 * Approval posts ONE ledger entry (entry_type adjustment, bucket adjusted, key adjustment:{uuid}) through LedgerService, so an
 * adjustment can never overwrite history, can be reversed, and respects period locks. Approvers are told through My Work.
 * Bulk grants / debits are the same thing in batches that share a bulk_key and have a preview.
 */
final class AdjustmentService
{
    public const KINDS = ['adjustment', 'grant', 'debit'];

    public const MAX_BULK = 1000;

    public function __construct(private LedgerService $ledger, private BalanceService $balances, private AccountService $accounts, private ExpiryService $expiry, private TaskService $tasks,
        private NotificationService $notifications, private DomainEvents $events, private DataScope $scope) {}

    // ---- single ---------------------------------------------------------------------------------------

    /** @param array{employee_id:int,leave_type_id:int,units:string|int|float,kind?:string,effective_date?:string,expires_on?:?string,reason:string,bulk_key?:?string} $d */
    public function request(array $d, User $maker, bool $notify = true): LeaveAdjustment
    {
        $d = $this->validated($d);
        $account = $this->accounts->ensure($d['employee_id'], $d['leave_type_id'], $d['effective_date']);
        $adjustment = LeaveAdjustment::create(['account_id' => $account->id, 'employee_id' => $d['employee_id'], 'leave_type_id' => $d['leave_type_id'], 'units' => $d['units'], 'kind' => $d['kind'],
            'effective_date' => $d['effective_date'], 'expires_on' => $d['expires_on'], 'status' => 'pending', 'bulk_key' => $d['bulk_key'] ?? null, 'reason' => $d['reason'], 'requested_by' => $maker->id]);
        Audit::record('leave_adjustment_requested', 'leave', 'leave_adjustment:'.$adjustment->uuid, ['units' => $d['units'], 'kind' => $d['kind'], 'employee_id' => $d['employee_id']]);
        if ($notify) {
            $this->assignApprovers($adjustment, $maker);
        }

        return $adjustment;
    }

    public function approve(LeaveAdjustment $adjustment, User $checker, ?string $note = null): LeaveAdjustment
    {
        $this->assertCanDecide($adjustment, $checker, true);

        return DB::transaction(function () use ($adjustment, $checker, $note) {
            $locked = LeaveAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new HttpException(409, 'This adjustment has already been decided.');
            }
            $units = Units::normalize($locked->getRawOriginal('units'));
            $effective = substr((string) $locked->getRawOriginal('effective_date'), 0, 10);
            $expires = $locked->getRawOriginal('expires_on') ? substr((string) $locked->getRawOriginal('expires_on'), 0, 10) : null;
            $entry = $this->ledger->post(new LedgerPosting($locked->account_id, 'adjustment', 'adjusted', $units, $effective, 'adjustment:'.$locked->uuid, expiresOn: Units::isNegative($units) ? null : $expires,
                referenceType: 'adjustment', referenceId: $locked->uuid, reason: mb_substr($locked->reason, 0, 500), createdBy: $locked->requested_by, enforceAvailable: $locked->kind === 'debit',
                meta: ['adjustment' => $locked->uuid, 'kind' => $locked->kind, 'requested_by' => $locked->requested_by, 'approved_by' => $checker->id, 'bulk_key' => $locked->bulk_key]))->entry;
            $locked->forceFill(['status' => 'posted', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note, 'ledger_entry_id' => $entry->id])->save();
            $this->expiry->sync();
            $this->tasks->completeBySource('leave', 'leave_adjustment:'.$locked->uuid);
            Audit::record('leave_adjustment_approved', 'leave', 'leave_adjustment:'.$locked->uuid, ['units' => $units, 'entry' => $entry->uuid]);
            $type = LeaveType::query()->find($locked->leave_type_id);
            $this->events->record(LeaveEvents::BALANCE_ADJUSTED, ['employee' => $locked->employee_id, 'summary' => 'Leave balance adjusted', 'effective_date' => $effective, 'actor_id' => $checker->id,
                'payload' => ['leave_type' => $type?->uuid, 'units' => $units, 'adjustment' => $locked->uuid, 'entry' => $entry->uuid]]);
            if ($userId = Actors::userIdOf($locked->employee_id)) {
                $this->notifications->send($userId, 'Leave balance adjusted', ($type?->name ?? 'Leave').' balance changed by '.$units.' effective '.$effective.'.', 'leave', '/leave');
            }
            if ($locked->requested_by !== $checker->id) {
                $this->notifications->send((int) $locked->requested_by, 'Leave adjustment approved', 'Your adjustment of '.$units.' was approved and posted.', 'leave', '/leave');
            }

            return $locked;
        });
    }

    public function reject(LeaveAdjustment $adjustment, User $checker, ?string $note = null): LeaveAdjustment
    {
        $this->assertCanDecide($adjustment, $checker, false);
        $updated = LeaveAdjustment::query()->whereKey($adjustment->id)->where('status', 'pending')->update(['status' => 'rejected', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note]);
        if (! $updated) {
            throw new HttpException(409, 'This adjustment has already been decided.');
        }
        $this->tasks->completeBySource('leave', 'leave_adjustment:'.$adjustment->uuid);
        Audit::record('leave_adjustment_rejected', 'leave', 'leave_adjustment:'.$adjustment->uuid, ['note' => $note]);
        if ($adjustment->requested_by !== $checker->id) {
            $this->notifications->send((int) $adjustment->requested_by, 'Leave adjustment rejected', 'Your adjustment request was rejected'.($note ? ': '.$note : '.'), 'leave', '/leave');
        }

        return $adjustment->refresh();
    }

    private function assertCanDecide(LeaveAdjustment $adjustment, User $checker, bool $approving): void
    {
        if ($adjustment->status !== 'pending') {
            throw new HttpException(409, 'This adjustment has already been decided.');
        }
        if (! $checker->allows('leave.adjust.approve')) {
            throw new HttpException(403, 'You are not allowed to decide leave adjustments.');
        }
        if (! $this->scope->canAccess($checker, $adjustment->employee_id, 'leave.adjust.approve')) {
            throw new HttpException(404, 'Not found.');
        }
        if ($approving && LeaveSettings::get('balance.maker_checker', true) && (int) $adjustment->requested_by === (int) $checker->id) {
            throw new HttpException(403, 'Maker-checker: an adjustment must be approved by someone other than the person who requested it.');
        }
    }

    // ---- bulk -----------------------------------------------------------------------------------------

    /**
     * Preview a bulk grant / debit without writing. @param  array{employee_ids:array<int,int>,leave_type_id:int,units:string|int|float,kind:string,effective_date?:string,expires_on?:?string,reason?:string} $d
     *
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function previewBulk(array $d, User $user): array
    {
        [$base, $ids] = $this->bulkBase($d, $user);
        $type = LeaveType::query()->findOrFail($base['leave_type_id']);
        $rows = [];
        $ok = $errors = 0;
        $total = '0.0000';
        foreach ($ids['reachable'] as $id) {
            $emp = Employee::query()->with('person')->find($id);
            $issues = [];
            $account = $this->accounts->covering($id, $type->id, $base['effective_date']);
            $current = $account ? $this->balances->balance($account, $base['effective_date'])->available : '0.0000';
            $projected = Units::add($current, $base['units']);
            if (! $account) {
                $issues[] = ['severity' => 'info', 'message' => 'A leave account will be opened for this period.'];
            }
            if (Units::isNegative($base['units']) && $account && Units::cmp($projected, Units::neg($account->getRawOriginal('negative_limit'))) < 0) {
                $issues[] = ['severity' => 'error', 'message' => 'The debit would take the balance below the allowed negative limit.'];
            }
            if (! in_array($emp?->status, Employee::WORKING, true)) {
                $issues[] = ['severity' => 'warning', 'message' => 'The employee is not currently working.'];
            }
            $hasError = (bool) array_filter($issues, fn ($i) => $i['severity'] === 'error');
            $hasError ? $errors++ : $ok++;
            $hasError || $total = Units::add($total, $base['units']);
            $rows[] = ['employee_uuid' => $emp?->uuid, 'employee_number' => $emp?->employee_number, 'name' => $emp?->person?->display_name, 'current_available' => $current, 'delta' => $base['units'], 'projected_available' => $projected,
                'issues' => $issues, 'will_apply' => ! $hasError];
        }
        foreach ($ids['unreachable'] as $x) {
            $errors++;
            $rows[] = ['employee_uuid' => null, 'employee_number' => null, 'name' => null, 'current_available' => null, 'delta' => $base['units'], 'projected_available' => null, 'issues' => [['severity' => 'error', 'message' => 'Employee '.$x.' was not found or is outside your data scope.']], 'will_apply' => false];
        }

        return ['rows' => $rows, 'summary' => ['employees' => count($rows), 'will_apply' => $ok, 'errors' => $errors, 'total_units' => $total, 'leave_type' => $type->code, 'kind' => $base['kind'], 'effective_date' => $base['effective_date']]];
    }

    /**
     * Records one pending adjustment per employee (all share a bulk_key). Repeating the same idempotency_key returns the same batch.
     *
     * @return array{bulk_key:string,created:int,skipped:array<int,array<string,mixed>>,duplicate:bool,adjustments:array<int,LeaveAdjustment>}
     */
    public function createBulk(array $d, User $maker): array
    {
        $bulkKey = 'bulk:'.($d['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid());
        $bulkKey = mb_substr($bulkKey, 0, 120);
        $existing = LeaveAdjustment::query()->where('bulk_key', $bulkKey)->get();
        if ($existing->isNotEmpty()) {
            return ['bulk_key' => $bulkKey, 'created' => 0, 'skipped' => [], 'duplicate' => true, 'adjustments' => $existing->all()];
        }
        if (trim((string) ($d['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }
        $preview = $this->previewBulk($d, $maker);
        [$base] = $this->bulkBase($d, $maker);
        $made = [];
        $skipped = [];
        DB::transaction(function () use ($preview, $base, $d, $maker, $bulkKey, &$made, &$skipped) {
            foreach ($preview['rows'] as $row) {
                if (! $row['will_apply']) {
                    $skipped[] = ['employee_uuid' => $row['employee_uuid'], 'issues' => $row['issues']];

                    continue;
                }
                $emp = Employee::query()->where('uuid', $row['employee_uuid'])->first();
                $made[] = $this->request(['employee_id' => $emp->id, 'leave_type_id' => $base['leave_type_id'], 'units' => $base['units'], 'kind' => $base['kind'], 'effective_date' => $base['effective_date'],
                    'expires_on' => $base['expires_on'], 'reason' => $d['reason'], 'bulk_key' => $bulkKey], $maker, false);
            }
        });
        if ($made) {
            $this->assignApprovers($made[0], $maker, $bulkKey, count($made));
        }

        return ['bulk_key' => $bulkKey, 'created' => count($made), 'skipped' => $skipped, 'duplicate' => false, 'adjustments' => $made];
    }

    /** @return array{approved:int,failed:array<int,array<string,mixed>>} */
    public function approveBulk(string $bulkKey, User $checker, ?string $note = null): array
    {
        $approved = 0;
        $failed = [];
        foreach (LeaveAdjustment::query()->where('bulk_key', $bulkKey)->where('status', 'pending')->orderBy('id')->get() as $adjustment) {
            try {
                $this->approve($adjustment, $checker, $note);
                $approved++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 403 && str_contains($e->getMessage(), 'Maker-checker')) {
                    throw $e;
                }
                $failed[] = ['adjustment' => $adjustment->uuid, 'reason' => $e->getMessage()];
            }
        }
        $this->tasks->completeBySource('leave', 'leave_adjustment_bulk:'.$bulkKey);

        return ['approved' => $approved, 'failed' => $failed];
    }

    public function rejectBulk(string $bulkKey, User $checker, ?string $note = null): int
    {
        $n = 0;
        foreach (LeaveAdjustment::query()->where('bulk_key', $bulkKey)->where('status', 'pending')->get() as $adjustment) {
            $this->reject($adjustment, $checker, $note);
            $n++;
        }
        $this->tasks->completeBySource('leave', 'leave_adjustment_bulk:'.$bulkKey);

        return $n;
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @return array{0:array<string,mixed>,1:array{reachable:array<int,int>,unreachable:array<int,mixed>}} */
    private function bulkBase(array $d, User $user): array
    {
        $ids = collect($d['employee_ids'] ?? [])->unique()->values();
        if ($ids->isEmpty() || $ids->count() > self::MAX_BULK) {
            throw ValidationException::withMessages(['employee_ids' => ['Choose between 1 and '.self::MAX_BULK.' employees.']]);
        }
        $base = $this->validated($d + ['reason' => $d['reason'] ?? 'bulk']);
        $reach = Ops::employees($user, 'leave.adjust')->whereIn('e.id', $ids->filter(fn ($v) => is_numeric($v))->all())->pluck('e.id')->map(fn ($v) => (int) $v)->all();
        $unreachable = $ids->reject(fn ($v) => in_array((int) $v, $reach, true))->values()->all();

        return [$base, ['reachable' => $reach, 'unreachable' => $unreachable]];
    }

    /** @return array<string,mixed> */
    private function validated(array $d): array
    {
        $kind = $d['kind'] ?? 'adjustment';
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => ['Choose adjustment, grant or debit.']]);
        }
        try {
            $units = Units::strict($d['units'] ?? '0');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['units' => [$e->getMessage()]]);
        }
        if (Units::isZero($units)) {
            throw ValidationException::withMessages(['units' => ['Units must not be zero.']]);
        }
        if ($kind === 'grant' && Units::isNegative($units)) {
            throw ValidationException::withMessages(['units' => ['A grant must be positive.']]);
        }
        if ($kind === 'debit') {
            $units = Units::neg(Units::abs($units));
        }
        $effective = $d['effective_date'] ?? TenantClock::today();
        $expires = $d['expires_on'] ?? null;
        foreach (['effective_date' => $effective, 'expires_on' => $expires] as $k => $v) {
            if ($v !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! CarbonImmutable::hasFormat($v, 'Y-m-d'))) {
                throw ValidationException::withMessages([$k => ['Use a date in Y-m-d format.']]);
            }
        }
        if ($expires !== null && $expires < $effective) {
            throw ValidationException::withMessages(['expires_on' => ['Expiry must not be before the effective date.']]);
        }
        if (trim((string) ($d['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        return ['employee_id' => (int) ($d['employee_id'] ?? 0), 'leave_type_id' => (int) ($d['leave_type_id'] ?? 0), 'units' => $units, 'kind' => $kind, 'effective_date' => $effective, 'expires_on' => $expires,
            'reason' => mb_substr(trim((string) $d['reason']), 0, 1000), 'bulk_key' => $d['bulk_key'] ?? null];
    }

    private function assignApprovers(LeaveAdjustment $adjustment, User $maker, ?string $bulkKey = null, int $count = 1): void
    {
        $exclude = LeaveSettings::get('balance.maker_checker', true) ? [$maker->id] : [];
        $assignees = collect(Actors::usersWithPermission('leave.adjust.approve', $exclude))
            ->filter(fn ($id) => ($u = User::query()->find($id)) && $this->scope->canAccess($u, $adjustment->employee_id, 'leave.adjust.approve'))->all();
        $entity = $bulkKey ? 'leave_adjustment_bulk:'.$bulkKey : 'leave_adjustment:'.$adjustment->uuid;
        foreach ($assignees as $userId) {
            $this->tasks->createOnce(['assignee_id' => $userId, 'title' => $bulkKey ? "Approve bulk leave adjustment ($count employees)" : 'Approve leave balance adjustment', 'description' => mb_substr($adjustment->reason, 0, 300),
                'source_module' => 'leave', 'source_entity' => $entity, 'priority' => 'normal', 'due_date' => null, 'action' => '/leave/adjustments', 'metadata' => ['adjustment' => $adjustment->uuid, 'bulk_key' => $bulkKey]]);
        }
    }

    /** @return array<string,mixed> */
    public function present(LeaveAdjustment $a): array
    {
        $employee = Employee::query()->with('person')->find($a->employee_id);
        $type = LeaveType::query()->find($a->leave_type_id);

        return ['id' => $a->uuid, 'status' => $a->status, 'kind' => $a->kind, 'units' => Units::normalize($a->getRawOriginal('units')), 'effective_date' => $a->getRawOriginal('effective_date') ? substr((string) $a->getRawOriginal('effective_date'), 0, 10) : null,
            'expires_on' => $a->getRawOriginal('expires_on') ? substr((string) $a->getRawOriginal('expires_on'), 0, 10) : null, 'reason' => $a->reason, 'bulk_key' => $a->bulk_key,
            'employee' => ['id' => $employee?->uuid, 'employee_number' => $employee?->employee_number, 'name' => $employee?->person?->display_name],
            'leave_type' => ['id' => $type?->uuid, 'code' => $type?->code, 'name' => $type?->name],
            'requested_by' => $a->requested_by, 'decided_by' => $a->decided_by, 'decided_at' => $a->decided_at?->toIso8601String(), 'decision_note' => $a->decision_note,
            'ledger_entry_id' => $a->ledger_entry_id ? \App\Domain\Leave\Models\LeaveLedgerEntry::query()->whereKey($a->ledger_entry_id)->value('uuid') : null, 'created_at' => $a->created_at?->toIso8601String()];
    }
}
