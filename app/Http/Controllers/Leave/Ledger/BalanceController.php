<?php

namespace App\Http\Controllers\Leave\Ledger;

use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\BalanceReadService;
use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveExpiry;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Units;
use App\Domain\Attendance\Operations\Ops;
use Illuminate\Http\Request;

class BalanceController extends LedgerController
{
    public function __construct(private BalanceReadService $reads) {}

    private function asOf(Request $r): ?string
    {
        $v = $r->validate(['as_of' => 'nullable|date_format:Y-m-d'])['as_of'] ?? null;

        return $v;
    }

    public function me(Request $r)
    {
        $employee = $this->reads->ownEmployee($r->user());
        abort_unless($employee, 404, 'No employee record is linked to your login.');
        $d = $r->validate(['all' => 'nullable|boolean', 'include' => 'nullable|in:trace', 'leave_type' => 'nullable|uuid']);

        return ['employee' => ['id' => $employee->uuid], 'data' => $this->reads->employeeBalances($employee, $r->user(), $this->asOf($r), ($d['include'] ?? null) === 'trace', (bool) ($d['all'] ?? false), $d['leave_type'] ?? null)];
    }

    public function employee(Request $r, string $employee)
    {
        $e = $this->viewableEmployee($r, $employee, $this->reads);
        $d = $r->validate(['all' => 'nullable|boolean', 'include' => 'nullable|in:trace', 'leave_type' => 'nullable|uuid']);

        return ['employee' => ['id' => $e->uuid], 'data' => $this->reads->employeeBalances($e, $r->user(), $this->asOf($r), ($d['include'] ?? null) === 'trace', (bool) ($d['all'] ?? false), $d['leave_type'] ?? null)];
    }

    public function team(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'leave_type' => 'nullable|uuid', 'page' => 'nullable|integer|min:1']);

        return $this->reads->team($r->user(), $this->asOf($r), (int) ($d['page'] ?? 1), $this->perPage($r), $d['q'] ?? null, $d['leave_type'] ?? null);
    }

    public function account(Request $r, string $account)
    {
        $a = $this->viewableAccount($r, $account, $this->reads);
        $type = LeaveType::query()->findOrFail($a->leave_type_id);

        return ['data' => $this->reads->presentAccount($a, $type, $this->asOf($r) ?? \App\Domain\Tenancy\TenantClock::today(), true)];
    }

    public function ledger(Request $r, string $account)
    {
        return ['data' => $this->reads->statement($this->viewableAccount($r, $account, $this->reads), $r->user(), $this->asOf($r))];
    }

    public function entry(Request $r, string $entry)
    {
        $e = LeaveLedgerEntry::byUuid($entry);
        abort_unless($this->reads->canView($r->user(), $e->employee_id), 404);
        $type = LeaveType::query()->find($e->leave_type_id);
        abort_if($type?->is_sensitive && ! $this->reads->canSeeSensitive($r->user(), $e->employee_id), 404);

        return ['data' => $this->reads->entry($e, $r->user())];
    }

    public function open(Request $r, AccountService $accounts)
    {
        $d = $r->validate(['employee' => 'required|uuid', 'leave_type' => 'required|uuid', 'date' => 'nullable|date_format:Y-m-d']);
        $e = $this->employeeByUuid($d['employee']);
        abort_unless(app(\App\Domain\Access\DataScope::class)->canAccess($r->user(), $e, 'leave.manage'), 404);
        $account = $accounts->ensure($e->id, $this->typeByUuid($d['leave_type'])->id, $d['date'] ?? \App\Domain\Tenancy\TenantClock::today());

        return response()->json(['data' => $this->reads->presentAccount($account, LeaveType::query()->findOrFail($account->leave_type_id), $d['date'] ?? \App\Domain\Tenancy\TenantClock::today())], 201);
    }

    /** Traceable entitlement / accrual calculation for one employee (read-only; nothing is posted). */
    public function calculate(Request $r, EntitlementEngine $engine)
    {
        $d = $r->validate(['employee' => 'required|uuid', 'leave_type' => 'required|uuid', 'mode' => 'nullable|in:entitlement,accrual', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $e = $this->employeeByUuid($d['employee']);
        abort_unless(app(\App\Domain\Access\DataScope::class)->canAccess($r->user(), $e, $r->user()->allows('leave.accrual.run') ? 'leave.accrual.run' : 'leave.manage'), 404);
        $type = $this->typeByUuid($d['leave_type']);
        $res = ($d['mode'] ?? 'entitlement') === 'accrual' ? $engine->accrualFor($e->id, $type->id, $d['from'], $d['to']) : $engine->entitlementFor($e->id, $type->id, $d['from'], $d['to']);
        foreach ($res['periods'] ?? [] as $i => $p) {
            unset($res['periods'][$i]['rule']);
        }

        return ['data' => $res + ['mode' => $d['mode'] ?? 'entitlement']];
    }

    public function myExpiries(Request $r)
    {
        $employee = $this->reads->ownEmployee($r->user());
        abort_unless($employee, 404);

        return ['data' => $this->expiries(LeaveExpiry::query()->where('employee_id', $employee->id))];
    }

    public function expiries(\Illuminate\Database\Eloquent\Builder|Request $q)
    {
        if ($q instanceof Request) {
            $r = $q;
            $ids = Ops::employees($r->user(), ['leave.manage', 'leave.team.view', 'leave.accrual.run'], false)->pluck('e.id');
            $q = LeaveExpiry::query()->whereIn('employee_id', $ids)->whereIn('status', ['scheduled', 'notified']);
            $wrap = true;
        }
        $types = LeaveType::query()->get()->keyBy('id');
        $rows = $q->where('expires_on', '>=', \App\Domain\Tenancy\TenantClock::today())->orderBy('expires_on')->limit(200)->get()->map(function ($x) use ($types) {
            $lot = collect(app(\App\Domain\Leave\Ledger\BalanceService::class)->lots($x->account_id, \App\Domain\Tenancy\TenantClock::today()))->firstWhere('entry_id', $x->source_entry_id);
            $t = $types[$x->leave_type_id] ?? null;

            return ['leave_type' => ['id' => $t?->uuid, 'code' => $t?->code, 'name' => $t?->name], 'units' => $lot['remaining'] ?? '0.0000', 'expires_on' => substr((string) $x->getRawOriginal('expires_on'), 0, 10), 'status' => $x->status,
                'employee_id' => \App\Domain\People\Models\Employee::query()->whereKey($x->employee_id)->value('uuid')];
        })->filter(fn ($x) => ! Units::isZero($x['units']))->values()->all();

        return isset($wrap) ? ['data' => $rows] : $rows;
    }
}
