<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveAccrualRun;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveLedgerAllocation;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read models for balances and the ledger (employee, team and HR views). Every number is derived from the ledger by BalanceService
 * and carries the ids of the entries behind it (`trace`). Visibility rules:
 *   - own data: leave.view
 *   - others: leave.team.view / leave.manage / leave.adjust / leave.reports.view within the holder's data scope
 *   - sensitive (medical) leave types are only shown to the employee themself or to holders of leave.medical.view
 */
final class BalanceReadService
{
    public const VIEW_PERMISSIONS = ['leave.team.view', 'leave.manage', 'leave.adjust', 'leave.reports.view'];

    public function __construct(private BalanceService $balances, private AccountService $accounts, private DataScope $scope) {}

    // ---- access ---------------------------------------------------------------------------------------

    public function ownEmployee(User $user): ?Employee
    {
        $row = Ops::ownEmployee($user);

        return $row ? Employee::query()->find($row->id) : null;
    }

    /** True when the user may read this employee's leave data (self with leave.view, or scoped team/HR access). */
    public function canView(User $user, Employee|int $employee): bool
    {
        if ($user->allows('leave.view') && $this->scope->isSelf($user, $employee)) {
            return true;
        }
        foreach (self::VIEW_PERMISSIONS as $permission) {
            if ($user->allows($permission) && $this->scope->canAccess($user, $employee, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function canSeeSensitive(User $user, int $employeeId): bool
    {
        return $this->scope->isSelf($user, $employeeId) || $user->allows('leave.medical.view');
    }

    // ---- balances -------------------------------------------------------------------------------------

    /**
     * Balances of one employee. By default only accounts whose period contains $asOf; `all` returns every period (closed too).
     *
     * @return array<int,array<string,mixed>>
     */
    public function employeeBalances(Employee $employee, User $viewer, ?string $asOf = null, bool $trace = false, bool $all = false, ?string $typeUuid = null): array
    {
        $asOf ??= TenantClock::today();
        $sensitiveOk = $this->canSeeSensitive($viewer, $employee->id);
        $q = LeaveAccount::query()->where('employee_id', $employee->id)->orderByDesc('period_start')->orderBy('leave_type_id');
        if (! $all) {
            $q->where('period_start', '<=', $asOf)->where('period_end', '>=', $asOf);
        }
        if ($typeUuid) {
            $q->whereIn('leave_type_id', LeaveType::query()->where('uuid', $typeUuid)->select('id'));
        }
        $types = LeaveType::query()->get()->keyBy('id');
        $out = [];
        foreach ($q->get() as $account) {
            $type = $types[$account->leave_type_id] ?? null;
            if (! $type || ($type->is_sensitive && ! $sensitiveOk)) {
                continue;
            }
            $out[] = $this->presentAccount($account, $type, $asOf, $trace);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function presentAccount(LeaveAccount $account, LeaveType $type, string $asOf, bool $trace = false): array
    {
        $b = $this->balances->balance($account, $asOf);
        $lots = $this->balances->lots($account, $asOf);
        $expiring = array_values(array_map(fn ($l) => ['entry_id' => LeaveLedgerEntry::query()->whereKey($l['entry_id'])->value('uuid'), 'units' => $l['remaining'], 'expires_on' => $l['expires_on']],
            array_filter($lots, fn ($l) => $l['expires_on'] !== null)));
        $balance = $b->toArray();
        $traceIds = $balance['trace'] ?? [];
        unset($balance['trace'], $balance['accountId']);
        $out = ['account' => ['id' => $account->uuid, 'status' => $account->status, 'period_start' => substr((string) $account->getRawOriginal('period_start'), 0, 10), 'period_end' => substr((string) $account->getRawOriginal('period_end'), 0, 10),
            'negative_limit' => Units::normalize($account->getRawOriginal('negative_limit')), 'max_balance' => $account->getRawOriginal('max_balance') !== null ? Units::normalize($account->getRawOriginal('max_balance')) : null],
            'leave_type' => $this->presentType($type), 'balance' => $balance, 'expiring' => $expiring];
        if (Units::isNegative($b->available) || Units::cmp($account->getRawOriginal('negative_limit'), '0') > 0) {
            $out['advance'] = $this->accounts->reconcileAdvance($account, $asOf);
        }
        if ($trace) {
            $out['trace'] = collect($traceIds)->map(fn ($ids) => LeaveLedgerEntry::query()->whereIn('id', $ids)->pluck('uuid')->all())->all();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function presentType(LeaveType $t): array
    {
        return ['id' => $t->uuid, 'code' => $t->code, 'name' => $t->name, 'kind' => $t->kind, 'unit' => $t->unit, 'color' => $t->color, 'is_statutory' => (bool) $t->is_statutory, 'is_sensitive' => (bool) $t->is_sensitive];
    }

    /**
     * Team balances (paginated by employee) within the viewer's data scope.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,int>}
     */
    public function team(User $viewer, ?string $asOf, int $page, int $perPage, ?string $search = null, ?string $typeUuid = null): array
    {
        $asOf ??= TenantClock::today();
        $q = Ops::employees($viewer, ['leave.team.view', 'leave.manage', 'leave.reports.view'], true)->select('e.id', 'e.uuid', 'e.employee_number', 'p.display_name')->orderBy('p.display_name')->orderBy('e.id');
        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $q->where(fn ($w) => $w->where('p.display_name', 'like', $like)->orWhere('e.employee_number', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get();
        $types = LeaveType::query()->where('active', true)->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $typeId = $typeUuid ? $types->firstWhere('uuid', $typeUuid)?->id : null;
        $accounts = LeaveAccount::query()->whereIn('employee_id', $rows->pluck('id'))->where('period_start', '<=', $asOf)->where('period_end', '>=', $asOf)
            ->when($typeId, fn ($x) => $x->where('leave_type_id', $typeId))->get()->groupBy('employee_id');
        $data = [];
        foreach ($rows as $r) {
            $sensitiveOk = $viewer->allows('leave.medical.view') || $this->scope->isSelf($viewer, $r->id);
            $items = [];
            foreach ($accounts[$r->id] ?? [] as $account) {
                $type = $types[$account->leave_type_id] ?? null;
                if (! $type || ($type->is_sensitive && ! $sensitiveOk)) {
                    continue;
                }
                $b = $this->balances->balance($account, $asOf);
                $items[] = ['leave_type' => ['id' => $type->uuid, 'code' => $type->code, 'name' => $type->name, 'unit' => $type->unit], 'account_id' => $account->uuid,
                    'entitled' => $b->entitled, 'consumed' => $b->consumed, 'pending' => $b->pending, 'scheduled' => $b->scheduled, 'available' => $b->available];
            }
            $data[] = ['employee' => ['id' => $r->uuid, 'employee_number' => $r->employee_number, 'name' => $r->display_name], 'balances' => $items];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / max(1, $perPage)))]];
    }

    // ---- ledger ---------------------------------------------------------------------------------------

    /**
     * Ledger statement of one account with running balance, links to the request / accrual run / adjustment / import behind each line.
     *
     * @return array<string,mixed>
     */
    public function statement(LeaveAccount $account, User $viewer, ?string $asOf = null): array
    {
        $employee = Employee::query()->findOrFail($account->employee_id);
        $type = LeaveType::query()->findOrFail($account->leave_type_id);
        $showDetail = ! $type->is_sensitive || $this->canSeeSensitive($viewer, $employee->id);
        $asOf ??= TenantClock::today();
        $rows = collect($this->balances->statement($account));
        $entries = LeaveLedgerEntry::query()->where('account_id', $account->id)->get()->keyBy('id');
        $links = $this->links($entries);
        $lines = $rows->map(function ($r) use ($entries, $links, $showDetail) {
            $e = $entries[$r['id']];

            return ['id' => $e->uuid, 'entry_type' => $e->entry_type, 'bucket' => $e->bucket, 'units' => $r['units'], 'running_available' => $r['running_available'], 'effective_date' => $r['effective_date'],
                'expires_on' => $r['expires_on'], 'reason' => $showDetail ? $e->reason : null, 'reverses' => $e->reverses_entry_id ? ($entries[$e->reverses_entry_id]->uuid ?? null) : null,
                'reversed_by' => $links['reversed_by'][$e->id] ?? null, 'source' => $links['source'][$e->id] ?? null, 'posted_at' => $e->posted_at?->toIso8601String(), 'explain' => $showDetail ? $this->explain($e) : null];
        })->values()->all();

        return ['account' => $this->presentAccount($account, $type, $asOf, true), 'entries' => $lines, 'integrity' => $this->balances->verify($account)];
    }

    /** One entry in full: calculation trace, lot allocations and what created it. @return array<string,mixed> */
    public function entry(LeaveLedgerEntry $e, User $viewer): array
    {
        $type = LeaveType::query()->find($e->leave_type_id);
        $showDetail = ! $type?->is_sensitive || $this->canSeeSensitive($viewer, $e->employee_id);
        $entries = LeaveLedgerEntry::query()->where('account_id', $e->account_id)->get()->keyBy('id');
        $links = $this->links($entries);
        $alloc = LeaveLedgerAllocation::query()->where('entry_id', $e->id)->get()->map(fn ($a) => ['lot_entry_id' => $entries[$a->source_entry_id]->uuid ?? null, 'units' => Units::normalize($a->getRawOriginal('units'))])->all();
        $used = LeaveLedgerAllocation::query()->where('source_entry_id', $e->id)->get()->map(fn ($a) => ['debit_entry_id' => LeaveLedgerEntry::query()->whereKey($a->entry_id)->value('uuid'), 'units' => Units::normalize($a->getRawOriginal('units'))])->all();

        return ['id' => $e->uuid, 'entry_type' => $e->entry_type, 'bucket' => $e->bucket, 'units' => Units::normalize($e->getRawOriginal('units')), 'effective_date' => substr((string) $e->getRawOriginal('effective_date'), 0, 10),
            'expires_on' => $e->expires_on ? substr((string) $e->getRawOriginal('expires_on'), 0, 10) : null, 'reason' => $showDetail ? $e->reason : null, 'idempotency_key' => $e->idempotency_key,
            'reverses' => $e->reverses_entry_id ? ($entries[$e->reverses_entry_id]->uuid ?? null) : null, 'reversed_by' => $links['reversed_by'][$e->id] ?? null, 'source' => $links['source'][$e->id] ?? null,
            'policy_version_id' => $e->policy_version_id, 'calculation' => $showDetail ? $e->meta : null, 'drawn_from_lots' => $alloc, 'consumed_by' => $used, 'posted_at' => $e->posted_at?->toIso8601String()];
    }

    /** Human explanation of one ledger line. */
    private function explain(LeaveLedgerEntry $e): string
    {
        $u = Units::normalize($e->getRawOriginal('units'));
        $meta = $e->meta ?? [];

        return match ($e->entry_type) {
            'accrual' => 'Accrued '.$u.' ('.($meta['basis'] ?? 'policy').' accrual, '.count($meta['occurrences'] ?? []).' occurrence(s)).',
            'opening' => 'Opening balance of '.$u.' loaded from migration.',
            'adjustment' => 'Manual adjustment of '.$u.' approved through maker-checker.',
            'carry_forward_in' => 'Carried in '.$u.' from the previous period.',
            'carry_forward_out' => 'Carried out '.Units::abs($u).' to the next period.',
            'expiry' => Units::abs($u).' unused units expired.',
            'reservation' => Units::abs($u).' held for a pending request.',
            'consumption' => Units::abs($u).' used by approved leave.',
            default => ucfirst(str_replace('_', ' ', $e->entry_type)).' '.$u.'.',
        };
    }

    /** @return array{reversed_by:array<int,string>,source:array<int,array<string,mixed>>} */
    private function links($entries): array
    {
        $reversedBy = [];
        foreach ($entries as $e) {
            if ($e->reverses_entry_id) {
                $reversedBy[$e->reverses_entry_id] = $e->uuid;
            }
        }
        $runIds = $entries->pluck('accrual_item_id')->filter()->all();
        $source = [];
        foreach ($entries as $e) {
            $s = [];
            $meta = $e->meta ?? [];
            if (! empty($meta['run'])) {
                $s['accrual_run'] = $meta['run'];
            }
            if ($e->request_id) {
                $s['request_id'] = DB::table('leave_requests')->where('id', $e->request_id)->value('uuid');
            }
            if (($meta['adjustment'] ?? null)) {
                $s['adjustment'] = $meta['adjustment'];
            }
            if (($meta['import'] ?? null)) {
                $s['import'] = $meta['import'];
            }
            if ($s) {
                $source[$e->id] = $s;
            }
        }
        unset($runIds);

        return ['reversed_by' => $reversedBy, 'source' => $source];
    }
}
