<?php

namespace App\Domain\Intelligence\Leakage;

use App\Domain\Access\DataScope;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic cost-leakage rules over data the viewer may already see. NO external AI and NO verdicts: each candidate is a lead with
 * evidence, an estimated exposure and the exact rule/threshold that raised it. Only employees inside the viewer's data scope are read,
 * so a candidate can never leak a record the viewer could not open.
 */
final class LeakageDetector
{
    public const PERMISSION = 'intelligence.leakage.view';

    private const EXPENSE_COUNTED = ['submitted', 'approved', 'paid'];

    private const ADJUSTMENT_KINDS = ['manual_net', 'correction'];

    public function __construct(private DataScope $scope) {}

    /** @return array<int,array<string,mixed>> candidate cases */
    public function detect(User $viewer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = LeakageRules::thresholds();
        $disabled = LeakageRules::disabled();
        $rules = ['ot_spike' => 'otSpike', 'ot_over_calculated' => 'otOverCalculated', 'regularization_overtime' => 'regularizationOvertime',
            'duplicate_expense' => 'duplicateExpense', 'repeat_payroll_adjustment' => 'repeatAdjustment'];
        $out = [];
        foreach ($rules as $code => $method) {
            if (! in_array($code, $disabled, true)) {
                array_push($out, ...$this->{$method}($viewer, $from, $to, $t));
            }
        }

        return $out;
    }

    public static function fingerprint(int $tenantId, string $rule, string $subject, CarbonImmutable $to): string
    {
        return hash('sha256', implode('|', ['tenant:'.$tenantId, $rule, $subject, 'month:'.$to->format('Y-m')]));
    }

    /** Source table joined to its employee and person, restricted to the viewer's data scope. */
    private function base(User $viewer, string $table): Builder
    {
        $q = DB::table("$table as src")->join('employees', 'employees.id', '=', 'src.employee_id')->join('people', 'people.id', '=', 'employees.person_id')
            ->where('src.tenant_id', $viewer->tenant_id)->where('employees.tenant_id', $viewer->tenant_id);

        return $this->scope->constrain($q, $viewer, self::PERMISSION);
    }

    private function otSpike(User $v, CarbonImmutable $from, CarbonImmutable $to, array $t): array
    {
        $days = $from->diffInDays($to) + 1;
        $baseFrom = $from->subDays($days * 3);
        $rows = $this->base($v, 'attendance_days')->whereBetween('src.attendance_date', [$baseFrom->toDateString(), $to->toDateString()])
            ->groupBy('src.employee_id', 'people.display_name', 'employees.uuid')
            ->selectRaw('src.employee_id, people.display_name as name, employees.uuid as employee_uuid, SUM(CASE WHEN src.attendance_date >= ? THEN src.approved_overtime_minutes ELSE 0 END) as current_minutes, SUM(CASE WHEN src.attendance_date < ? THEN src.approved_overtime_minutes ELSE 0 END) as baseline_total',
                [$from->toDateString(), $from->toDateString()])->get();
        $out = [];
        foreach ($rows as $r) {
            $current = (int) $r->current_minutes;
            $baseline = (int) round($r->baseline_total / 3);
            $limit = $baseline > 0 ? max($t['ot_spike_min_minutes'], $baseline * $t['ot_spike_ratio']) : $t['ot_spike_min_minutes'] * 2;
            if ($current < $limit) {
                continue;
            }
            $out[] = $this->candidate('ot_spike', $v, $from, $to, (int) $r->employee_id, 'e'.$r->employee_id,
                "{$r->name}: approved overtime {$this->hours($current)} h vs usual {$this->hours($baseline)} h",
                'Approved overtime is well above this employee\'s own trailing average. Review whether the work was genuinely required.',
                null, null, max(0, $current - $baseline),
                ['employee' => ['uuid' => $r->employee_uuid, 'name' => $r->name], 'window_minutes' => $current, 'baseline_minutes_per_window' => $baseline, 'baseline_windows' => 3, 'window_days' => $days],
                ['rule' => 'Approved overtime in the window >= max(minimum, baseline x ratio); with no baseline, >= 2 x minimum.', 'threshold' => ['ratio' => $t['ot_spike_ratio'], 'min_minutes' => $t['ot_spike_min_minutes']],
                    'sources' => ['attendance_days.approved_overtime_minutes'], 'exposure' => 'Minutes above the baseline average; not converted to money because no hourly rate is assumed.']);
        }

        return $out;
    }

    private function otOverCalculated(User $v, CarbonImmutable $from, CarbonImmutable $to, array $t): array
    {
        $rows = $this->base($v, 'attendance_days')->whereBetween('src.attendance_date', [$from->toDateString(), $to->toDateString()])->whereColumn('src.approved_overtime_minutes', '>', 'src.overtime_minutes')
            ->groupBy('src.employee_id', 'people.display_name', 'employees.uuid')
            ->selectRaw('src.employee_id, people.display_name as name, employees.uuid as employee_uuid, SUM(src.approved_overtime_minutes - src.overtime_minutes) as excess, COUNT(*) as day_count')->get();
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->excess < $t['ot_over_calculated_min_minutes']) {
                continue;
            }
            $out[] = $this->candidate('ot_over_calculated', $v, $from, $to, (int) $r->employee_id, 'e'.$r->employee_id,
                "{$r->name}: {$this->hours((int) $r->excess)} h overtime approved beyond what punches support",
                'Overtime approved for more minutes than the attendance engine calculated. Confirm the extra minutes have a documented reason.',
                null, null, (int) $r->excess,
                ['employee' => ['uuid' => $r->employee_uuid, 'name' => $r->name], 'excess_minutes' => (int) $r->excess, 'days' => (int) $r->day_count],
                ['rule' => 'Sum over days of (approved overtime minutes - calculated overtime minutes) where approved > calculated.', 'threshold' => ['min_minutes' => $t['ot_over_calculated_min_minutes']],
                    'sources' => ['attendance_days.approved_overtime_minutes', 'attendance_days.overtime_minutes'], 'exposure' => 'Excess minutes; no hourly rate assumed.']);
        }

        return $out;
    }

    private function regularizationOvertime(User $v, CarbonImmutable $from, CarbonImmutable $to, array $t): array
    {
        $reg = $this->base($v, 'attendance_regularizations')->where('src.status', 'approved')->whereBetween('src.attendance_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('src.employee_id', 'people.display_name', 'employees.uuid')->havingRaw('COUNT(*) >= ?', [$t['regularization_min_count']])
            ->selectRaw('src.employee_id, people.display_name as name, employees.uuid as employee_uuid, COUNT(*) as reg_count')->get();
        if ($reg->isEmpty()) {
            return [];
        }
        $ot = $this->base($v, 'attendance_days')->whereIn('src.employee_id', $reg->pluck('employee_id'))->whereBetween('src.attendance_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('src.employee_id')->selectRaw('src.employee_id, SUM(src.approved_overtime_minutes) as minutes')->pluck('minutes', 'employee_id');
        $out = [];
        foreach ($reg as $r) {
            $minutes = (int) ($ot[$r->employee_id] ?? 0);
            if ($minutes < $t['regularization_overtime_min_minutes']) {
                continue;
            }
            $out[] = $this->candidate('regularization_overtime', $v, $from, $to, (int) $r->employee_id, 'e'.$r->employee_id,
                "{$r->name}: {$r->reg_count} regularizations and {$this->hours($minutes)} h approved overtime",
                'Missing or corrected punches together with overtime can mean punches are being repaired after the fact. Review the regularized days.',
                null, null, $minutes,
                ['employee' => ['uuid' => $r->employee_uuid, 'name' => $r->name], 'regularizations' => (int) $r->reg_count, 'approved_overtime_minutes' => $minutes],
                ['rule' => 'Approved regularizations >= minimum count AND approved overtime >= minimum minutes in the same window.', 'threshold' => ['min_regularizations' => $t['regularization_min_count'], 'min_overtime_minutes' => $t['regularization_overtime_min_minutes']],
                    'sources' => ['attendance_regularizations', 'attendance_days.approved_overtime_minutes'], 'exposure' => 'Approved overtime minutes in the window.']);
        }

        return $out;
    }

    private function duplicateExpense(User $v, CarbonImmutable $from, CarbonImmutable $to, array $t): array
    {
        $q = DB::table('expense_lines as src')->join('expense_claims', 'expense_claims.id', '=', 'src.claim_id')->join('employees', 'employees.id', '=', 'expense_claims.employee_id')
            ->join('people', 'people.id', '=', 'employees.person_id')->where('src.tenant_id', $v->tenant_id)->where('expense_claims.tenant_id', $v->tenant_id)
            ->whereIn('expense_claims.status', self::EXPENSE_COUNTED)->whereBetween('src.spent_on', [$from->subDays(90)->toDateString(), $to->toDateString()]);
        $this->scope->constrain($q, $v, self::PERMISSION);
        $rows = $q->select('src.spent_on', 'src.merchant', 'src.amount_minor', 'expense_claims.uuid as claim_uuid', 'expense_claims.id as claim_id', 'expense_claims.title as claim_title',
            'expense_claims.currency', 'expense_claims.minor_units', 'employees.id as employee_id', 'employees.uuid as employee_uuid', 'people.display_name as name')->get();
        $groups = [];
        foreach ($rows as $r) {
            // Same merchant, date and amount across DIFFERENT people is common (fares, set meals), so by default only one person's repeats count.
            $groups[mb_strtolower(trim($r->merchant)).'|'.$r->spent_on.'|'.$r->amount_minor.'|'.$r->currency.($t['duplicate_cross_employee'] ? '' : '|e'.$r->employee_id)][] = $r;
        }
        $out = [];
        foreach ($groups as $key => $set) {
            $claims = collect($set)->unique('claim_id');
            if ($claims->count() < 2) {
                continue;
            }
            $first = $set[0];
            $single = $claims->pluck('employee_id')->unique()->count() === 1;
            $unit = round($first->amount_minor / (10 ** $first->minor_units), $first->minor_units);
            $out[] = $this->candidate('duplicate_expense', $v, $from, $to, $single ? (int) $first->employee_id : null, 'x'.hash('sha256', $key),
                trim($first->merchant).' on '.$first->spent_on.': same amount claimed '.$claims->count().' times',
                $single ? 'The same employee claimed an identical merchant, date and amount more than once.' : 'Different employees claimed an identical merchant, date and amount. This can be a shared expense or a copied receipt.',
                round($unit * ($claims->count() - 1), $first->minor_units), $first->currency, null,
                ['merchant' => trim($first->merchant), 'spent_on' => $first->spent_on, 'amount' => $unit,
                    'claims' => $claims->map(fn ($s) => ['claim_uuid' => $s->claim_uuid, 'title' => $s->claim_title, 'employee' => $s->name, 'employee_uuid' => $s->employee_uuid])->values()->all()],
                ['rule' => 'Lines from different submitted/approved/paid claims '.($t['duplicate_cross_employee'] ? 'by any employees' : 'by the same employee').' with the same merchant (case-insensitive), spent date, amount and currency.', 'threshold' => ['min_claims' => 2, 'cross_employee' => (bool) $t['duplicate_cross_employee']],
                    'sources' => ['expense_lines', 'expense_claims'], 'exposure' => 'Amount x (claims - 1): what would be recoverable if all but one are duplicates.']);
        }

        return $out;
    }

    private function repeatAdjustment(User $v, CarbonImmutable $from, CarbonImmutable $to, array $t): array
    {
        $rows = $this->base($v, 'payroll_adjustments')->whereIn('src.kind', self::ADJUSTMENT_KINDS)->whereNotIn('src.status', ['rejected', 'reversed'])
            ->where('src.created_at', '>=', $from->startOfDay())->where('src.created_at', '<=', $to->endOfDay())
            ->groupBy('src.employee_id', 'src.currency', 'people.display_name', 'employees.uuid')->havingRaw('COUNT(*) >= ?', [$t['repeat_adjustment_min_count']])
            ->selectRaw('src.employee_id, src.currency, people.display_name as name, employees.uuid as employee_uuid, COUNT(*) as adj_count, SUM(ABS(src.amount)) as total')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->candidate('repeat_payroll_adjustment', $v, $from, $to, (int) $r->employee_id, 'e'.$r->employee_id.'|'.$r->currency,
                "{$r->name}: {$r->adj_count} manual payroll adjustments ({$r->currency} ".number_format((float) $r->total, 2).')',
                'Repeated manual corrections for one employee can signal a broken input or an override pattern. Check the reasons recorded on each adjustment.',
                round((float) $r->total, 4), $r->currency, null,
                ['employee' => ['uuid' => $r->employee_uuid, 'name' => $r->name], 'adjustments' => (int) $r->adj_count, 'kinds' => self::ADJUSTMENT_KINDS],
                ['rule' => 'Count of manual_net/correction adjustments (not rejected or reversed) created in the window >= minimum.', 'threshold' => ['min_count' => $t['repeat_adjustment_min_count']],
                    'sources' => ['payroll_adjustments'], 'exposure' => 'Sum of absolute adjustment amounts: the amount that bypassed the normal calculation, not all of it is leakage.']);
        }

        return $out;
    }

    private function candidate(string $rule, User $v, CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId, string $subject, string $title, string $summary,
        ?float $amount, ?string $currency, ?int $minutes, array $evidence, array $explain): array
    {
        return ['rule_code' => $rule, 'severity' => LeakageRules::CATALOG[$rule]['severity'], 'fingerprint' => self::fingerprint($v->tenant_id, $rule, $subject, $to), 'employee_id' => $employeeId,
            'window_from' => $from->toDateString(), 'window_to' => $to->toDateString(), 'title' => mb_substr($title, 0, 300), 'summary' => $summary, 'exposure_amount' => $amount,
            'currency' => $currency, 'exposure_minutes' => $minutes, 'evidence' => $evidence,
            'explain' => $explain + ['as_of' => ['generated_at' => now()->toIso8601String(), 'tenant_date' => TenantClock::today(), 'timezone' => TenantClock::timezone()],
                'scope' => 'Only employees inside the viewer\'s data scope for intelligence.leakage.view were read.', 'deterministic' => true,
                'caveat' => 'A case is a lead for human review. It does not establish error, fraud or intent.']];
    }

    private function hours(int $minutes): string
    {
        return number_format($minutes / 60, 1);
    }
}
