<?php

namespace App\Domain\Leave\Expiry;

use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Support\Units;

/**
 * Decides which credit lots a debit draws on (source-to-use trace). Request workflows (workstream C) call allocate() and pass the
 * result as LedgerPosting::$allocations / LedgerService::commitReservation(..., allocations:).
 *
 * Orders (policy data: rules.consumption_order, default earliest_expiring):
 *   earliest_expiring  lots expiring first, never-expiring last, then oldest
 *   current_first      this period's own credits (accrued/opening/adjusted) before carried-in ones, then by expiry
 *   carried_first      carried-in lots first, then by expiry
 *   oldest_first       by effective date (FIFO)
 * Lots already expired on the leave date are never used.
 */
final class ConsumptionPlanner
{
    public const ORDERS = ['earliest_expiring', 'current_first', 'carried_first', 'oldest_first'];

    /** Aliases accepted from the policy rule validator (workstream A): fifo = oldest_first, expiry_first = earliest_expiring. */
    public const ALIASES = ['fifo' => 'oldest_first', 'expiry_first' => 'earliest_expiring'];

    public static function normalizeOrder(?string $order): string
    {
        $order = self::ALIASES[$order] ?? $order;

        return in_array($order, self::ORDERS, true) ? $order : 'earliest_expiring';
    }

    public function __construct(private BalanceService $balances) {}

    /**
     * @return array{allocations:array<int,array{source_entry_id:int,units:string}>,covered:string,shortfall:string,order:string}
     */
    public function allocate(LeaveAccount|int $account, string $units, string $leaveDate, string $order = 'earliest_expiring'): array
    {
        $order = self::normalizeOrder($order);
        $lots = array_values(array_filter($this->balances->lots($account, $leaveDate), fn ($l) => $l['expires_on'] === null || $l['expires_on'] >= $leaveDate));
        $far = '9999-12-31';
        usort($lots, function ($a, $b) use ($order, $far) {
            $byExpiry = [$a['expires_on'] ?? $far, $a['effective_date'], $a['entry_id']] <=> [$b['expires_on'] ?? $far, $b['effective_date'], $b['entry_id']];

            return match ($order) {
                'current_first' => [$a['bucket'] === 'carried' ? 1 : 0] <=> [$b['bucket'] === 'carried' ? 1 : 0] ?: $byExpiry,
                'carried_first' => [$a['bucket'] === 'carried' ? 0 : 1] <=> [$b['bucket'] === 'carried' ? 0 : 1] ?: $byExpiry,
                'oldest_first' => [$a['effective_date'], $a['entry_id']] <=> [$b['effective_date'], $b['entry_id']],
                default => $byExpiry,
            };
        });
        $need = Units::abs($units);
        $allocations = [];
        foreach ($lots as $lot) {
            if (Units::isZero($need)) {
                break;
            }
            $take = Units::min($need, $lot['remaining']);
            $allocations[] = ['source_entry_id' => $lot['entry_id'], 'units' => $take];
            $need = Units::sub($need, $take);
        }

        return ['allocations' => $allocations, 'covered' => Units::sub(Units::abs($units), $need), 'shortfall' => $need, 'order' => $order];
    }
}
