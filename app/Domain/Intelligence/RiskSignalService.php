<?php

namespace App\Domain\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Intelligence\Models\IntelSettings;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic workforce indicators FOR HUMAN REVIEW. Not a prediction, not a score, no automated decision.
 * Disabled unless the tenant enables it. Output is cohort-level only (department), never individual. Cohorts smaller than the
 * threshold are merged/omitted and indicator counts below the threshold are shown as "<N" (secondary suppression), so a single person
 * cannot be inferred. Only these inputs are read: tenure, probation, approved-leave request count, approved overtime minutes, active
 * performance-improvement plan presence. No sensitive attributes (age, gender, health, ethnicity, religion, ...) and no leave types/reasons.
 */
final class RiskSignalService
{
    public const WINDOW_DAYS = 90;

    public const INDICATORS = [
        'early_tenure' => ['label' => 'Joined in the last 12 months', 'rule' => 'joined_on within 365 days of today'],
        'in_probation' => ['label' => 'In probation, or probation extended', 'rule' => 'probation applicable and not yet confirmed, or probation_extensions > 0'],
        'frequent_leave' => ['label' => 'Frequent approved leave', 'rule' => '3 or more approved leave requests starting in the last 90 days'],
        'high_overtime' => ['label' => 'High overtime', 'rule' => 'approved overtime of 40 hours (2400 minutes) or more in the last 90 days'],
        'performance_flag' => ['label' => 'Active performance improvement plan', 'rule' => 'at least one performance improvement plan with status active'],
    ];

    public const WORDING = 'These are indicators for human review only. They are not predictions, scores or decisions about any person, and must not be used as the sole basis for any employment action.';

    public function settings(): array
    {
        $s = IntelSettings::current();

        return ['risk_signals_enabled' => $s->risk_signals_enabled, 'min_cohort' => max(3, (int) $s->min_cohort)];
    }

    public function saveSettings(User $actor, bool $enabled, int $minCohort): array
    {
        $s = IntelSettings::query()->first() ?? new IntelSettings;
        $s->fill(['risk_signals_enabled' => $enabled, 'min_cohort' => max(3, $minCohort), 'updated_by' => $actor->id])->save();
        Audit::record('intelligence.settings.updated', 'intelligence', 'settings', ['risk_signals_enabled' => $enabled, 'min_cohort' => max(3, $minCohort)]);

        return $this->settings();
    }

    public function cohorts(User $viewer): array
    {
        $cfg = $this->settings();
        $base = ['enabled' => $cfg['risk_signals_enabled'], 'min_cohort' => $cfg['min_cohort'], 'human_review_notice' => self::WORDING, 'definitions' => self::INDICATORS, 'window_days' => self::WINDOW_DAYS,
            'privacy' => 'Cohort-level only. Groups smaller than the minimum are merged or withheld; indicator counts below the minimum are shown as "<N". No sensitive attributes are used.'];
        if (! $cfg['risk_signals_enabled']) {
            return $base + ['cohorts' => [], 'message' => 'Risk signals are disabled for this workspace. A workspace administrator can enable them in Intelligence settings.'];
        }
        $today = TenantClock::today();
        $q = DB::table('employees')->leftJoin('employee_assignments as a', function ($j) use ($today) {
            $j->on('a.employee_id', '=', 'employees.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
        })->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where('employees.tenant_id', $viewer->tenant_id)->whereNull('employees.deleted_at')->whereIn('employees.status', Employee::WORKING);
        app(DataScope::class)->constrain($q, $viewer, 'intelligence.risk_signals.view');
        $rows = $q->get(['employees.id', 'employees.joined_on', 'employees.probation_applicable', 'employees.confirmation_date', 'employees.probation_extensions', 'd.name as department'])->unique('id');
        $ids = $rows->pluck('id')->all();
        $since = now(TenantClock::timezone())->subDays(self::WINDOW_DAYS)->toDateString();
        $leave = DB::table('leave_requests')->where('tenant_id', $viewer->tenant_id)->whereIn('employee_id', $ids)->where('status', 'approved')->where('start_date', '>=', $since)->groupBy('employee_id')->selectRaw('employee_id, count(*) as c')->pluck('c', 'employee_id');
        $ot = DB::table('overtime_requests')->where('tenant_id', $viewer->tenant_id)->whereIn('employee_id', $ids)->whereIn('status', ['approved', 'partially_approved'])->where('work_date', '>=', $since)->groupBy('employee_id')->selectRaw('employee_id, sum(approved_minutes) as m')->pluck('m', 'employee_id');
        $pip = DB::table('performance_pips')->where('tenant_id', $viewer->tenant_id)->whereIn('employee_id', $ids)->where('status', 'active')->distinct()->pluck('employee_id')->flip();
        $year = now(TenantClock::timezone())->subDays(365)->toDateString();
        $groups = [];
        foreach ($rows as $r) {
            $g = $r->department ?: 'Unassigned';
            $groups[$g]['size'] = ($groups[$g]['size'] ?? 0) + 1;
            $flags = ['early_tenure' => substr((string) $r->joined_on, 0, 10) >= $year, 'in_probation' => ($r->probation_applicable && ! $r->confirmation_date) || (int) $r->probation_extensions > 0,
                'frequent_leave' => (int) ($leave[$r->id] ?? 0) >= 3, 'high_overtime' => (int) ($ot[$r->id] ?? 0) >= 2400, 'performance_flag' => isset($pip[$r->id])];
            foreach ($flags as $k => $on) {
                $groups[$g]['ind'][$k] = ($groups[$g]['ind'][$k] ?? 0) + ($on ? 1 : 0);
            }
        }
        $min = $cfg['min_cohort'];
        $out = [];
        $small = ['size' => 0, 'ind' => [], 'groups' => 0];
        foreach ($groups as $name => $g) {
            if ($g['size'] >= $min) {
                $out[] = $this->cohort($name, $g['size'], $g['ind'], $min);
            } else {
                $small['size'] += $g['size'];
                $small['groups']++;
                foreach ($g['ind'] as $k => $v) {
                    $small['ind'][$k] = ($small['ind'][$k] ?? 0) + $v;
                }
            }
        }
        $withheld = 0;
        if ($small['groups'] > 0) {
            if ($small['size'] >= $min && $small['groups'] > 1) { // merging a single small group would not protect anyone
                $out[] = $this->cohort('Other (small groups combined)', $small['size'], $small['ind'], $min);
            } else {
                $withheld = $small['size'];
            }
        }
        Audit::record('intelligence.risk_signals.viewed', 'intelligence', 'cohorts', ['cohorts' => count($out), 'withheld_employees' => $withheld]);

        return $base + ['cohorts' => $out, 'suppressed' => ['employees_in_groups_below_minimum' => $withheld > 0 ? '<'.$min.' per group (withheld)' : 0, 'groups_merged_or_withheld' => $small['groups']],
            'as_of' => $today];
    }

    private function cohort(string $name, int $size, array $ind, int $min): array
    {
        $shown = [];
        foreach (self::INDICATORS as $k => $_) {
            $v = (int) ($ind[$k] ?? 0);
            $shown[$k] = $v === 0 ? 0 : ($v < $min ? '<'.$min : $v);
        }

        return ['cohort' => $name, 'size' => $size, 'indicators' => $shown];
    }
}
