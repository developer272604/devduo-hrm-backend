<?php

namespace App\Domain\Leave\Ledger;

use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerAllocation;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\Units;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * Derives balances from the ledger. There is no stored balance anywhere: every number here is a bcmath sum of signed
 * ledger rows and carries the ids of the rows behind it (Balance::$trace) for the Leave Explainer.
 *
 * Counting rule for `balance()` (the "usable now" view): debits always count (a reservation or scheduled leave dated in the
 * future still holds balance), as do releases/reversals in debit buckets; grants (opening/accrued/carried/adjusted credits) count only once their
 * effective_date has arrived. Pass $strictDates for forecasts
 * ("what will the balance be ON that date"), which counts only rows effective on or before the date.
 */
final class BalanceService
{
    /** Buckets that hold grants. Only these are held back until their effective date; releases/reversals in debit buckets always count. */
    public const CREDIT_BUCKETS = ['opening', 'accrued', 'carried', 'adjusted'];

    public function balance(LeaveAccount|int $account, ?string $asOf = null, bool $strictDates = false): Balance
    {
        $accountId = $account instanceof LeaveAccount ? $account->id : $account;
        $asOf ??= TenantClock::today();
        $sums = array_fill_keys(LeaveLedgerEntry::BUCKETS, '0.0000');
        $trace = [];
        $rows = LeaveLedgerEntry::query()->where('account_id', $accountId)->orderBy('id')->get(['id', 'bucket', 'units', 'effective_date', 'reverses_entry_id']);
        $dates = $rows->mapWithKeys(fn ($r) => [$r->id => substr((string) $r->getRawOriginal('effective_date'), 0, 10)]);
        foreach ($rows as $row) {
            $units = Units::normalize($row->getRawOriginal('units'));
            $date = substr((string) $row->getRawOriginal('effective_date'), 0, 10);
            if ($strictDates) {
                $counts = $date <= $asOf;
            } elseif ($row->reverses_entry_id && in_array($row->bucket, self::CREDIT_BUCKETS, true)) {
                // A reversal of a grant counts exactly when the grant it cancels counts, so the pair always nets to zero.
                $counts = ($dates[$row->reverses_entry_id] ?? $date) <= $asOf;
            } else {
                $counts = $date <= $asOf || Units::isNegative($units) || ! in_array($row->bucket, self::CREDIT_BUCKETS, true);
            }
            if (! $counts) {
                continue;
            }
            $sums[$row->bucket] = Units::add($sums[$row->bucket], $units);
            $trace[$row->bucket][] = $row->id;
        }

        $entitled = Units::sum([$sums['opening'], $sums['accrued'], $sums['carried'], $sums['adjusted']]);
        $consumed = Units::neg($sums['consumed']);
        $pending = Units::neg($sums['pending']);
        $scheduled = Units::neg($sums['scheduled']);
        $expired = Units::neg($sums['expired']);
        $encashed = Units::neg($sums['encashed']);
        $available = Units::sum($sums);

        return new Balance($accountId, $sums['opening'], $sums['accrued'], $sums['carried'], $sums['adjusted'], $consumed, $pending, $scheduled, $expired, $encashed, $entitled, $available, $asOf, $trace);
    }

    /** Balances of every account an employee holds (optionally one leave type), newest period first. @return array<int,array{account:LeaveAccount,balance:Balance}> */
    public function forEmployee(int $employeeId, ?string $asOf = null, ?int $leaveTypeId = null, bool $openOnly = true): array
    {
        $accounts = LeaveAccount::query()->where('employee_id', $employeeId)
            ->when($leaveTypeId, fn ($q) => $q->where('leave_type_id', $leaveTypeId))
            ->when($openOnly, fn ($q) => $q->where('status', 'open'))
            ->orderByDesc('period_start')->get();

        return $accounts->map(fn (LeaveAccount $a) => ['account' => $a, 'balance' => $this->balance($a, $asOf)])->all();
    }

    /** Ledger statement with running available, in effective order: the "ledger explanation" shown to employees. @return array<int,array<string,mixed>> */
    public function statement(LeaveAccount|int $account): array
    {
        $accountId = $account instanceof LeaveAccount ? $account->id : $account;
        $running = '0.0000';
        $out = [];
        $rows = LeaveLedgerEntry::query()->where('account_id', $accountId)->orderBy('effective_date')->orderBy('id')->get();
        foreach ($rows as $row) {
            $units = Units::normalize($row->getRawOriginal('units'));
            $running = Units::add($running, $units);
            $out[] = ['id' => $row->id, 'uuid' => $row->uuid, 'entry_type' => $row->entry_type, 'bucket' => $row->bucket, 'units' => $units, 'running_available' => $running,
                'effective_date' => substr((string) $row->getRawOriginal('effective_date'), 0, 10), 'expires_on' => $row->expires_on ? substr((string) $row->getRawOriginal('expires_on'), 0, 10) : null,
                'reason' => $row->reason, 'request_id' => $row->request_id, 'reverses_entry_id' => $row->reverses_entry_id, 'meta' => $row->meta];
        }

        return $out;
    }

    /**
     * Credit lots that still have units left, in consumption order: earliest-expiring first (no expiry last), then oldest.
     * Workstream B changes the ordering by sorting this result (current-first etc.); the remaining units are always exact.
     *
     * @return array<int,array{entry_id:int,bucket:string,units:string,remaining:string,expires_on:?string,effective_date:string}>
     */
    public function lots(LeaveAccount|int $account, ?string $asOf = null): array
    {
        $accountId = $account instanceof LeaveAccount ? $account->id : $account;
        $asOf ??= TenantClock::today();
        $reversed = LeaveLedgerEntry::query()->where('account_id', $accountId)->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->all();
        $used = [];
        foreach (LeaveLedgerAllocation::query()->whereIn('source_entry_id', LeaveLedgerEntry::query()->where('account_id', $accountId)->select('id'))->get() as $alloc) {
            if (! in_array($alloc->entry_id, $reversed, true)) {
                $used[$alloc->source_entry_id] = Units::add($used[$alloc->source_entry_id] ?? '0', $alloc->getRawOriginal('units'));
            }
        }
        $lots = [];
        $credits = LeaveLedgerEntry::query()->where('account_id', $accountId)->where('units', '>', 0)->whereIn('bucket', ['opening', 'accrued', 'carried', 'adjusted'])->get();
        foreach ($credits as $credit) {
            $date = substr((string) $credit->getRawOriginal('effective_date'), 0, 10);
            if ($date > $asOf || in_array($credit->id, $reversed, true) || $credit->reverses_entry_id) {
                continue;
            }
            $units = Units::normalize($credit->getRawOriginal('units'));
            $remaining = Units::sub($units, $used[$credit->id] ?? '0');
            if (Units::cmp($remaining, '0') > 0) {
                $lots[] = ['entry_id' => $credit->id, 'bucket' => $credit->bucket, 'units' => $units, 'remaining' => $remaining,
                    'expires_on' => $credit->expires_on ? substr((string) $credit->getRawOriginal('expires_on'), 0, 10) : null, 'effective_date' => $date];
            }
        }
        usort($lots, fn ($a, $b) => [$a['expires_on'] ?? '9999-12-31', $a['effective_date'], $a['entry_id']] <=> [$b['expires_on'] ?? '9999-12-31', $b['effective_date'], $b['entry_id']]);

        return $lots;
    }

    /** Integrity check: the exact bcmath sum must equal the database's own sum of the column (detects tampering/corruption). */
    public function verify(LeaveAccount|int $account): array
    {
        $accountId = $account instanceof LeaveAccount ? $account->id : $account;
        $exact = Units::sum(LeaveLedgerEntry::query()->where('account_id', $accountId)->get(['units'])->map(fn ($r) => $r->getRawOriginal('units')));
        $db = Units::normalize(DB::table('leave_ledger_entries')->where('account_id', $accountId)->sum('units'));

        return ['exact' => $exact, 'database' => $db, 'consistent' => Units::cmp($exact, $db) === 0];
    }
}
