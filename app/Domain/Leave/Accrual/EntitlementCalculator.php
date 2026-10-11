<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Pure entitlement / accrual calculator (reads policy + ledger, never writes). Implements the EntitlementEngine contract.
 *
 * Model: a policy rule has a `basis` (annual|monthly|weekly|daily|pay_period|anniversary|manual) and an `amount` credited per
 * occurrence of that basis. The schedule inside a leave period gives occurrences; every occurrence is priced:
 *
 *   amount   = tenure band amount at the occurrence date, else the rule amount
 *   factor   = share of the occurrence's natural span (the month, week, period...) the employee is employed and eligible for,
 *              with joiner / leaver handling per policy (daily | full | none | cutoff) and probation / waiting-period handling
 *   units    = round(amount * factor)   (rounding is policy data: nearest|up|down to 0.5 or 1)
 *   date     = occurrence date, shifted to the end of probation / waiting period when the policy defers instead of forfeits
 *
 * Transfers: the policy is resolved AS OF each occurrence date (per month for fine-grained bases), so a transfer to another
 * policy mid-period simply changes the rule used from that date; annual rules are split at the change date and prorated.
 * Caps (maximum per period, maximum balance) are applied at accrual time against the ledger. Occurrences already credited
 * (recorded in ledger meta.occurrences) are never credited twice, which makes runs over overlapping windows safe.
 */
final class EntitlementCalculator implements EntitlementEngine
{
    public function __construct(private PeriodResolver $periods, private BalanceService $balances) {}

    private function resolver(): LeavePolicyResolver
    {
        return app(LeavePolicyResolver::class);
    }

    public function entitlementFor(int $employeeId, int $leaveTypeId, string $periodStart, string $periodEnd): array
    {
        $emp = Employee::query()->find($employeeId);
        if (! $emp) {
            return $this->empty('employee_not_found');
        }
        $ctx = $this->context($emp, $leaveTypeId);
        $probe = $this->clamp(max($periodStart, $ctx['joined'] ?? $periodStart), $periodStart, $periodEnd);
        $first = $this->ruleAt($ctx, $probe);
        if (! $first) {
            return $this->empty('type_not_granted');
        }
        $occ = $this->priced($ctx, $first, ['start' => $periodStart, 'end' => $periodEnd]);
        $lines = array_map(fn ($o) => $o['line'], array_filter($occ, fn ($o) => $o['effective_date'] <= $periodEnd));
        $total = Units::sum(array_column($lines, 'units'));
        if ($first['max_per_period'] !== null && Units::cmp($total, $first['max_per_period']) > 0) {
            $lines[] = ['label' => 'Capped at the maximum per period', 'units' => Units::sub($first['max_per_period'], $total), 'detail' => ['max_per_period' => $first['max_per_period']]];
            $total = $first['max_per_period'];
        }

        return ['units' => $total, 'lines' => array_values($lines), 'skipped' => $lines ? null : 'not_eligible', 'basis' => $first['basis'], 'rule_version_id' => $first['version_id'],
            'period' => ['start' => $periodStart, 'end' => $periodEnd]];
    }

    public function accrualFor(int $employeeId, int $leaveTypeId, string $from, string $to): array
    {
        $emp = Employee::query()->find($employeeId);
        if (! $emp) {
            return $this->empty('employee_not_found');
        }
        $ctx = $this->context($emp, $leaveTypeId);
        $first = $this->ruleAt($ctx, $this->clamp(max($from, $ctx['joined'] ?? $from), $from, $to));
        if (! $first) {
            return $this->empty('type_not_granted');
        }
        if ($first['basis'] === 'manual') {
            return $this->empty('manual_basis');
        }
        $segments = [];
        $lines = [];
        $total = '0.0000';
        $cursor = $from;
        $guard = 0;
        $notes = [];
        while ($cursor <= $to && $guard++ < 60) {
            $period = $this->periods->forDate($ctx['joined'], $first['cycle'], $cursor);
            $segTo = min($period['end'], $to);
            $seg = $this->periodAccrual($ctx, $first, $period, $cursor, $segTo);
            $notes = array_merge($notes, $seg['notes']);
            if ($seg['lines']) {
                $segments[] = ['period_start' => $period['start'], 'period_end' => $period['end'], 'cycle_period_id' => $period['cycle_period_id'], 'units' => $seg['units'], 'occurrences' => $seg['occurrences'],
                    'effective_date' => $seg['effective_date'], 'rule_version_id' => $first['version_id'], 'lines' => $seg['lines'], 'rule' => $first];
                $lines = array_merge($lines, $seg['lines']);
                $total = Units::add($total, $seg['units']);
            }
            $cursor = CarbonImmutable::parse($period['end'])->addDay()->toDateString();
        }
        $skipped = null;
        if (Units::isZero($total)) {
            $skipped = in_array('already_accrued', $notes, true) ? 'already_accrued' : (in_array('capped', $notes, true) ? 'capped' : (in_array('not_eligible', $notes, true) ? 'not_eligible' : 'nothing_due'));
        }

        return ['units' => $total, 'lines' => $lines, 'skipped' => $skipped, 'basis' => $first['basis'], 'rule_version_id' => $first['version_id'], 'periods' => $segments, 'notes' => array_values(array_unique($notes))];
    }

    // ---- per period accrual -----------------------------------------------------------------------------

    /** @return array{units:string,lines:array,occurrences:array<int,string>,effective_date:?string,notes:array<int,string>} */
    private function periodAccrual(array $ctx, array $rule, array $period, string $from, string $to): array
    {
        $notes = [];
        $candidates = array_values(array_filter($this->priced($ctx, $rule, ['start' => $period['start'], 'end' => $period['end']]), fn ($o) => $o['effective_date'] >= $from && $o['effective_date'] <= $to && $o['effective_date'] <= $period['end']));
        if (! $candidates) {
            return ['units' => '0.0000', 'lines' => [], 'occurrences' => [], 'effective_date' => null, 'notes' => ['nothing_due']];
        }
        $account = LeaveAccount::query()->where('employee_id', $ctx['emp']->id)->where('leave_type_id', $ctx['type'])->where('period_start', $period['start'])->first();
        $posted = $account ? $this->postedOccurrences($account) : [];
        $lines = [];
        $occurrences = [];
        $units = '0.0000';
        $accrued = $account ? $this->balances->balance($account, $to)->accrued : '0.0000';
        $available = $account ? $this->balances->balance($account, $to)->available : '0.0000';
        $effective = null;
        foreach ($candidates as $o) {
            if (in_array($o['date'], $posted, true)) {
                $notes[] = 'already_accrued';

                continue;
            }
            if (Units::isZero($o['units'])) {
                $notes[] = 'not_eligible';

                continue;
            }
            $take = $o['units'];
            $line = $o['line'];
            if ($rule['max_per_period'] !== null) {
                $room = Units::max('0', Units::sub($rule['max_per_period'], Units::add($accrued, $units)));
                if (Units::cmp($take, $room) > 0) {
                    $line['detail']['capped_by'] = 'max_per_period';
                    $take = $room;
                }
            }
            if ($rule['max_balance'] !== null) {
                $room = Units::max('0', Units::sub($rule['max_balance'], Units::add($available, $units)));
                if (Units::cmp($take, $room) > 0) {
                    $line['detail']['capped_by'] = 'max_balance';
                    $take = $room;
                }
            }
            if (Units::cmp($take, $o['units']) < 0) {
                $notes[] = 'capped';
                $line['detail']['uncapped_units'] = $o['units'];
                $line['label'] .= ' (capped)';
            }
            if (Units::isZero($take)) {
                continue;
            }
            $line['units'] = $take;
            $lines[] = $line;
            $occurrences[] = $o['date'];
            $units = Units::add($units, $take);
            $effective = max($effective ?? '', $o['effective_date']);
        }

        return ['units' => $units, 'lines' => $lines, 'occurrences' => $occurrences, 'effective_date' => $effective, 'notes' => $notes];
    }

    /** Dates of accrual occurrences already credited (and not reversed) in an account. @return array<int,string> */
    public function postedOccurrences(LeaveAccount $account): array
    {
        $reversed = LeaveLedgerEntry::query()->where('account_id', $account->id)->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->all();
        $dates = [];
        foreach (LeaveLedgerEntry::query()->where('account_id', $account->id)->where('entry_type', 'accrual')->where('units', '>', 0)->get(['id', 'meta']) as $e) {
            if (! in_array($e->id, $reversed, true)) {
                foreach ($e->meta['occurrences'] ?? [] as $d) {
                    $dates[] = $d;
                }
            }
        }

        return $dates;
    }

    // ---- occurrences ------------------------------------------------------------------------------------

    /**
     * Every priced occurrence of the period (the full schedule), ordered by effective date.
     *
     * @param  array{start:string,end:string}  $period
     * @return array<int,array{date:string,effective_date:string,units:string,line:array<string,mixed>}>
     */
    private function priced(array &$ctx, array $first, array $period): array
    {
        $out = [];
        foreach ($this->schedule($ctx, $first, $period) as $occ) {
            $rule = $occ['rule'] ?? $this->ruleAt($ctx, $occ['date']);
            if (! $rule) {
                continue;
            }
            $out[] = $this->price($ctx, $rule, $occ);
        }
        usort($out, fn ($a, $b) => [$a['effective_date'], $a['date']] <=> [$b['effective_date'], $b['date']]);

        return $out;
    }

    /** @return array<int,array{date:string,from:string,to:string,cover_from:string,cover_to:string,rule?:array}> */
    private function schedule(array &$ctx, array $rule, array $period): array
    {
        $pS = CarbonImmutable::parse($period['start']);
        $pE = CarbonImmutable::parse($period['end']);
        $start = $rule['timing'] === 'start';
        $mk = fn (CarbonImmutable $nf, CarbonImmutable $nt, ?array $rl = null) => (function () use ($nf, $nt, $pS, $pE, $start, $rl) {
            $cf = $nf->max($pS);
            $ct = $nt->min($pE);

            return ['date' => ($start ? $cf : $ct)->toDateString(), 'from' => $nf->toDateString(), 'to' => $nt->toDateString(), 'cover_from' => $cf->toDateString(), 'cover_to' => $ct->toDateString()] + ($rl ? ['rule' => $rl] : []);
        })();
        $out = [];
        switch ($rule['basis']) {
            case 'annual':
            case 'manual':
                $endRule = $this->ruleAt($ctx, $pE->toDateString());
                $change = ($endRule && $endRule['version_id'] !== $rule['version_id']) ? $this->changeDate($ctx, $rule['version_id'], $pS->toDateString(), $pE->toDateString()) : null;
                if ($change) {
                    $cd = CarbonImmutable::parse($change);
                    $a = $mk($pS, $pE, $rule);
                    $a['cover_to'] = $cd->subDay()->toDateString();
                    $a['date'] = $pS->toDateString();
                    $b = $mk($pS, $pE, $endRule);
                    $b['cover_from'] = $cd->toDateString();
                    $b['date'] = $cd->toDateString();
                    $b['transfer'] = true;
                    $out = [$a, $b];
                } else {
                    $out = [$mk($pS, $pE, $rule)];
                }
                break;
            case 'monthly':
                for ($m = $pS->startOfMonth(); $m->lte($pE); $m = $m->addMonth()) {
                    $out[] = $mk($m, $m->endOfMonth()->startOfDay());
                }
                break;
            case 'weekly':
                for ($w = $pS->startOfWeek(); $w->lte($pE); $w = $w->addWeek()) {
                    $out[] = $mk($w, $w->addDays(6));
                }
                break;
            case 'daily':
                for ($d = $pS; $d->lte($pE); $d = $d->addDay()) {
                    $out[] = $mk($d, $d);
                }
                break;
            case 'pay_period':
                $len = max(1, (int) ($rule['pay_period']['length_days'] ?? 14));
                $anchor = CarbonImmutable::parse($rule['pay_period']['anchor'] ?? $pS->toDateString());
                $diff = (int) floor($anchor->diffInDays($pS, false) / $len);
                for ($s = $anchor->addDays($diff * $len); $s->lte($pE); $s = $s->addDays($len)) {
                    $e = $s->addDays($len - 1);
                    if ($e->gte($pS)) {
                        $out[] = $mk($s, $e);
                    }
                }
                break;
            case 'anniversary':
                $joined = $ctx['joined'] ? CarbonImmutable::parse($ctx['joined']) : null;
                if ($joined) {
                    for ($y = $pS->year; $y <= $pE->year; $y++) {
                        $first = CarbonImmutable::create($y, $joined->month, 1);
                        $date = $first->setDay(min($joined->day, $first->daysInMonth));
                        if ($date->gt($joined) && $date->gte($pS) && $date->lte($pE)) {
                            $out[] = ['date' => $date->toDateString(), 'from' => $date->toDateString(), 'to' => $date->toDateString(), 'cover_from' => $date->toDateString(), 'cover_to' => $date->toDateString()];
                        }
                    }
                }
                break;
        }

        return $out;
    }

    /** First date in [from,to] on which the policy version differs from $versionId (assumes a single change). */
    private function changeDate(array &$ctx, int $versionId, string $from, string $to): ?string
    {
        $lo = CarbonImmutable::parse($from);
        $hi = CarbonImmutable::parse($to);
        $differs = fn (CarbonImmutable $d) => ($r = $this->ruleAt($ctx, $d->toDateString())) && $r['version_id'] !== $versionId;
        if ($differs($lo) || ! $differs($hi)) {
            return null;
        }
        while ($lo->diffInDays($hi, true) > 1) {
            $mid = $lo->addDays((int) floor($lo->diffInDays($hi, true) / 2));
            $differs($mid) ? $hi = $mid : $lo = $mid;
        }

        return $hi->toDateString();
    }

    // ---- pricing ----------------------------------------------------------------------------------------

    /** @return array{date:string,effective_date:string,units:string,line:array<string,mixed>} */
    private function price(array $ctx, array $rule, array $occ): array
    {
        $joined = $ctx['joined'];
        $months = $joined ? $this->months($joined, $occ['date']) : 0;
        $amount = $rule['amount'];
        $band = null;
        foreach ($rule['tenure_bands'] as $b) {
            if ($months >= $b['from_months'] && ($b['to_months'] === null || $months < $b['to_months'])) {
                $amount = $b['amount'];
                $band = $b;
            }
        }
        $p = $rule['proration'];
        $nat = ['from' => $occ['from'], 'to' => $occ['to']];
        $cover = ['from' => $occ['cover_from'], 'to' => $occ['cover_to']];
        $nDays = max(1, $this->days($nat['from'], $nat['to']));
        $sep = $ctx['separated'];
        $lost = $nDays - $this->days($cover['from'], $cover['to']);
        $notes = [];
        $employedFrom = $joined ?? $cover['from'];
        $employedTo = $sep ?? $cover['to'];
        $coverEmployed = ['from' => max($cover['from'], $employedFrom), 'to' => min($cover['to'], $employedTo)];
        if ($coverEmployed['from'] > $coverEmployed['to']) {
            $lost = $nDays;
            $notes[] = 'not employed in this span';
        } else {
            if ($joined && $joined > $cover['from']) {
                $lj = match ($p['joiner']) {
                    'full' => 0, 'none' => $nDays,
                    'cutoff' => $this->days($nat['from'], $joined) <= $p['joiner_cutoff_day'] ? 0 : $nDays,
                    default => $this->days($cover['from'], CarbonImmutable::parse($joined)->subDay()->toDateString()),
                };
                $lost += $lj;
                $notes[] = "joiner:{$p['joiner']}";
            }
            if ($sep && $sep < $cover['to']) {
                $ll = match ($p['leaver']) {
                    'full' => 0, 'none' => $nDays,
                    'cutoff' => $this->days($nat['from'], $sep) >= $p['leaver_cutoff_day'] ? 0 : $nDays,
                    default => $this->days(CarbonImmutable::parse($sep)->addDay()->toDateString(), $cover['to']),
                };
                $lost += $ll;
                $notes[] = "leaver:{$p['leaver']}";
            }
            $excludeUntil = null;
            if ($p['probation'] === 'none' && $ctx['probation_end']) {
                $excludeUntil = $ctx['probation_end'];
                $notes[] = 'probation days excluded';
            }
            if ($rule['waiting'] === 'forfeit' && $rule['start_after_days'] > 0 && $joined) {
                $w = CarbonImmutable::parse($joined)->addDays($rule['start_after_days'] - 1)->toDateString();
                $excludeUntil = max($excludeUntil ?? '', $w);
                $notes[] = 'waiting period forfeited';
            }
            if ($excludeUntil) {
                $lost += $this->days(max($coverEmployed['from'], $cover['from']), min($excludeUntil, $coverEmployed['to']));
            }
        }
        $factor = bcdiv((string) max(0, $nDays - $lost), (string) $nDays, 10);
        $units = Units::round(bcmul($amount, $factor, 14));
        $units = $this->round($units, (string) $p['rounding']);

        $effective = $occ['date'];
        $defer = null;
        if ($rule['waiting'] === 'defer' && $rule['start_after_days'] > 0 && $joined) {
            $defer = CarbonImmutable::parse($joined)->addDays($rule['start_after_days'])->toDateString();
        }
        if ($p['probation'] === 'defer' && $ctx['probation_end']) {
            $defer = max($defer ?? '', CarbonImmutable::parse($ctx['probation_end'])->addDay()->toDateString());
        }
        if ($defer && $defer > $effective) {
            $effective = $defer;
            $notes[] = "deferred to $defer";
        }
        if ($joined && $joined > $effective) {
            $effective = $joined;
        }
        if ($sep && $effective > $sep && $p['leaver'] === 'daily') {
            $units = '0.0000';
            $notes[] = 'deferred past separation';
        }
        $label = ucfirst(str_replace('_', ' ', $rule['basis'])).' accrual '.$occ['date'];

        return ['date' => $occ['date'], 'effective_date' => $effective, 'units' => $units, 'line' => ['label' => $label, 'units' => $units, 'detail' => array_filter([
            'occurrence' => $occ['date'], 'effective_date' => $effective, 'basis' => $rule['basis'], 'amount' => $amount, 'tenure_band' => $band, 'tenure_months' => $months, 'factor' => rtrim(rtrim($factor, '0'), '.') ?: '0',
            'span' => $nat, 'cover' => $cover, 'policy_version_id' => $rule['version_id'], 'notes' => $notes ?: null, 'transfer_split' => ! empty($occ['transfer']) ?: null,
        ], fn ($v) => $v !== null)]];
    }

    private function round(string $units, string $mode): string
    {
        return RuleReader::roundUnits($units, $mode);
    }

    // ---- context helpers --------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function context(Employee $emp, int $typeId): array
    {
        $raw = fn (string $c) => ($v = $emp->getRawOriginal($c)) ? substr((string) $v, 0, 10) : null;
        $probationEnd = null;
        if ($emp->probation_applicable && ($raw('confirmation_date') ?: $raw('probation_end'))) {
            $probationEnd = $raw('confirmation_date') ? CarbonImmutable::parse($raw('confirmation_date'))->subDay()->toDateString() : $raw('probation_end');
        }

        return ['emp' => $emp, 'type' => $typeId, 'joined' => $raw('joined_on'), 'separated' => $raw('separated_on'), 'probation_end' => $probationEnd, 'cache' => []];
    }

    /** Normalised rule for the employee/type on a date (memoised per month; org data is read as of the date by the resolver). */
    private function ruleAt(array &$ctx, string $date): ?array
    {
        $key = substr($date, 0, 7);
        if (! array_key_exists($key, $ctx['cache'])) {
            $resolved = $this->resolver()->rulesFor($ctx['emp']->id, $ctx['type'], $date);
            $ctx['cache'][$key] = $resolved ? RuleReader::read($resolved) : null;
        }

        return $ctx['cache'][$key];
    }

    private function months(string $from, string $to): int
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);
        $m = ($b->year - $a->year) * 12 + ($b->month - $a->month) - ($b->day < $a->day ? 1 : 0);

        return max(0, $m);
    }

    private function days(string $from, string $to): int
    {
        return $from > $to ? 0 : (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }

    private function clamp(string $d, string $min, string $max): string
    {
        return max($min, min($d, $max));
    }

    private function empty(string $reason): array
    {
        return ['units' => '0.0000', 'lines' => [], 'skipped' => $reason];
    }
}
