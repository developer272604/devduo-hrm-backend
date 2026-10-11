<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opens (and finds) leave accounts: one per employee, leave type and period. The negative limit and the accumulation cap are
 * copied from the policy when the account is opened (the ledger enforces them from the account row).
 *
 * Negative balance rules (policy data): limits.negative_limit = how far below zero; limits.advance_leave = true allows borrowing
 * against not-yet-accrued entitlement up to the period's full entitlement when no explicit negative_limit is given.
 */
final class AccountService
{
    public function __construct(private PeriodResolver $periods, private BalanceService $balances) {}

    /**
     * @param  array{version_id:int,type_id:int,rules:array}|null  $resolved  already-resolved policy rules (saves a lookup)
     */
    public function ensure(int $employeeId, int $leaveTypeId, string $date, ?array $resolved = null): LeaveAccount
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $resolved ??= app(LeavePolicyResolver::class)->rulesFor($employeeId, $leaveTypeId, $date);
        $rule = $resolved ? RuleReader::read($resolved) : null;
        $joined = $employee->getRawOriginal('joined_on') ? substr((string) $employee->getRawOriginal('joined_on'), 0, 10) : null;
        $period = $this->periods->forDate($joined, $rule['cycle'] ?? null, $date);
        $existing = $this->find($employeeId, $leaveTypeId, $period['start']);
        if ($existing) {
            return $existing;
        }
        $negative = $rule ? $this->negativeLimit($rule, $employeeId, $leaveTypeId, $period) : RuleReader::defaultNegativeLimit();
        try {
            return LeaveAccount::create(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'cycle_period_id' => $period['cycle_period_id'], 'policy_version_id' => $rule['version_id'] ?? null,
                'period_start' => $period['start'], 'period_end' => $period['end'], 'status' => 'open', 'negative_limit' => $negative, 'max_balance' => $rule['max_balance'] ?? null,
                'meta' => ['opened_by' => 'system', 'cycle_kind' => $period['kind']]]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($employeeId, $leaveTypeId, $period['start']) ?? throw new \LogicException('Leave account could not be opened.');
        }
    }

    /** The period an account for this employee/type/date would cover (nothing is created). @return array{start:string,end:string,cycle_id:?int,cycle_period_id:?int,label:string,kind:string} */
    public function periodFor(int $employeeId, int $leaveTypeId, string $date): array
    {
        $joined = Employee::query()->whereKey($employeeId)->value('joined_on');
        $resolved = app(LeavePolicyResolver::class)->rulesFor($employeeId, $leaveTypeId, $date);

        return $this->periods->forDate($joined ? substr((string) $joined, 0, 10) : null, $resolved ? RuleReader::read($resolved)['cycle'] : null, $date);
    }

    public function find(int $employeeId, int $leaveTypeId, string $periodStart): ?LeaveAccount
    {
        return LeaveAccount::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('period_start', $periodStart)->first();
    }

    /** The account whose period contains the date, if one is open. */
    public function covering(int $employeeId, int $leaveTypeId, string $date): ?LeaveAccount
    {
        return LeaveAccount::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('period_start', '<=', $date)->where('period_end', '>=', $date)->orderByDesc('period_start')->first();
    }

    private function negativeLimit(array $rule, int $employeeId, int $typeId, array $period): string
    {
        if ($rule['negative_limit'] !== null) {
            return Units::max('0', $rule['negative_limit']);
        }
        if ($rule['advance_leave']) {
            $e = app(EntitlementEngine::class)->entitlementFor($employeeId, $typeId, $period['start'], $period['end']);

            return Units::max('0', $e['units']);
        }

        return RuleReader::defaultNegativeLimit();
    }

    /**
     * Future accrual reconciliation for a negative / advance balance: how much is borrowed now, what the engine still expects to
     * credit before the period ends, and whether that covers the borrowing.
     *
     * @return array{borrowed:string,expected_future_accrual:string,shortfall:string,covered:bool,negative_limit:string}
     */
    public function reconcileAdvance(LeaveAccount $account, ?string $today = null): array
    {
        $today ??= \App\Domain\Tenancy\TenantClock::today();
        $balance = $this->balances->balance($account, $today);
        $borrowed = Units::isNegative($balance->available) ? Units::abs($balance->available) : '0.0000';
        $expected = '0.0000';
        if (! Units::isZero($borrowed) && $today < $account->period_end) {
            $from = \Carbon\CarbonImmutable::parse($today)->addDay()->toDateString();
            $expected = app(EntitlementEngine::class)->accrualFor($account->employee_id, $account->leave_type_id, $from, $account->period_end)['units'];
        }
        $shortfall = Units::max('0', Units::sub($borrowed, $expected));

        return ['borrowed' => $borrowed, 'expected_future_accrual' => $expected, 'shortfall' => $shortfall, 'covered' => Units::isZero($shortfall), 'negative_limit' => Units::normalize($account->getRawOriginal('negative_limit'))];
    }
}
