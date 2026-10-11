<?php

namespace App\Domain\Payroll\Readiness;

use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payroll readiness (spec 38/72/90). Deterministic: the state comes ONLY from explicit counts of unresolved conditions, never a score.
 *   BLOCKED          any open check of severity blocking|critical
 *   PROCESSING       a calculation/validation is underway for the period (and nothing blocks)
 *   REVIEW_REQUIRED  only warnings are open, or a source module is not enabled / could not be read
 *   READY            every check has a count of zero
 * Read-only. Sources owned by other modules are read by table (attendance, leave, people).
 */
final class ReadinessEngine
{
    private const RANK = ['information' => 0, 'warning' => 1, 'blocking' => 2, 'critical' => 3];

    /** @return array<int,array<string,mixed>> one evaluation per active payroll group (internal keys _roster/_blocked included) */
    public function groups(User $viewer): array
    {
        $out = [];
        foreach (PayrollGroup::where('active', true)->orderBy('name')->get() as $group) {
            $out[] = $this->evaluate($viewer, $group);
        }

        return $out;
    }

    /** Tenant-wide overview (keeps the original `state` and `issues` keys). */
    public function overview(User $viewer): array
    {
        $groups = $this->groups($viewer);
        $issues = [];
        foreach ($groups as $g) {
            foreach ($g['checks'] as $c) {
                if ($c['state'] === 'open' && self::RANK[$c['severity']] >= 2) {
                    $issues[] = ['code' => $c['key'], 'message' => $c['label'].': '.$c['count'].' '.$c['unit'], 'severity' => $c['severity'], 'group' => $g['group']['code'], 'period' => $g['period']['label'] ?? null];
                }
            }
        }
        if (! $groups) {
            $issues[] = ['code' => 'no_payroll_groups', 'message' => 'No active payroll group is configured.', 'severity' => 'blocking', 'group' => null, 'period' => null];
            $state = 'blocked';
        } else {
            $states = array_column($groups, 'state');
            $state = in_array('blocked', $states, true) ? 'blocked' : (in_array('processing', $states, true) ? 'processing' : (in_array('review_required', $states, true) ? 'review_required' : 'ready'));
        }

        return ['state' => $state, 'status' => strtoupper($state), 'rule' => 'State is derived from explicit open conditions only; there is no score.',
            'groups' => array_map(fn ($g) => ['group' => $g['group'], 'period' => $g['period'], 'state' => $g['state'], 'employees_expected' => $g['employees_expected'], 'employees_ready' => $g['employees_ready'],
                'percent_ready' => $g['percent_ready'], 'blocking' => $g['counts']['blocking'], 'warning' => $g['counts']['warning']], $groups), 'issues' => $issues];
    }

    /** Readiness map by payroll_group | legal_entity | location | department. */
    public function map(User $viewer, string $dimension): array
    {
        $evals = $this->groups($viewer);
        if ($dimension === 'payroll_group') {
            return array_map(fn ($g) => ['id' => $g['group']['uuid'], 'name' => $g['group']['name'], 'state' => $g['state'], 'employees' => $g['employees_expected'], 'ready_employees' => $g['employees_ready'], 'percent' => $g['percent_ready'],
                'blockers' => collect($g['checks'])->filter(fn ($c) => $c['state'] === 'open' && self::RANK[$c['severity']] >= 2)->map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'count' => $c['count']])->values()->all()], $evals);
        }
        $columns = ['legal_entity' => ['legal_entity_id', 'legal_entities'], 'location' => ['location_id', 'locations'], 'department' => ['department_id', 'departments']];
        abort_unless(isset($columns[$dimension]), 422, 'Unknown breakdown.');
        [$column, $table] = $columns[$dimension];
        $date = PayrollOps::today();
        $buckets = [];
        foreach ($evals as $g) {
            $ids = array_column($g['_roster'], 'id');
            $asg = $ids ? DB::table('employee_assignments')->whereIn('employee_id', $ids)->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->pluck($column, 'employee_id') : collect();
            foreach ($g['_roster'] as $e) {
                $key = (int) ($asg[$e['id']] ?? 0);
                $buckets[$key]['employees'] = ($buckets[$key]['employees'] ?? 0) + 1;
                $blockers = $g['_blocked'][$e['id']] ?? [];
                $buckets[$key]['ready_employees'] = ($buckets[$key]['ready_employees'] ?? 0) + ($blockers ? 0 : 1);
                foreach ($blockers as $k) {
                    $buckets[$key]['blockers'][$k] = ($buckets[$key]['blockers'][$k] ?? 0) + 1;
                }
            }
        }
        $names = $buckets ? DB::table($table)->whereIn('id', array_keys($buckets))->pluck('name', 'id') : collect();
        $out = [];
        foreach ($buckets as $key => $b) {
            $out[] = ['id' => $key ?: null, 'name' => $names[$key] ?? 'Unassigned', 'employees' => $b['employees'], 'ready_employees' => $b['ready_employees'], 'percent' => round($b['ready_employees'] / max(1, $b['employees']) * 100, 1),
                'state' => ($b['blockers'] ?? []) ? 'blocked' : 'ready', 'blockers' => collect($b['blockers'] ?? [])->map(fn ($c, $k) => ['key' => $k, 'count' => $c])->values()->all()];
        }

        return $out;
    }

    public static function clean(array $evaluation): array
    {
        unset($evaluation['_roster'], $evaluation['_blocked']);

        return $evaluation;
    }

    /** @return array<string,mixed> */
    public function evaluate(User $viewer, PayrollGroup $group, ?string $periodUuid = null): array
    {
        $today = PayrollOps::today();
        $period = $this->period($group, $periodUuid, $today);
        $checks = [];
        $skipped = [];
        $roster = [];
        $people = collect();
        $from = $to = $today;
        if ($period) {
            $from = substr((string) $period->getRawOriginal('period_start'), 0, 10);
            $to = substr((string) $period->getRawOriginal('period_end'), 0, 10);
        }
        $add = function (string $key, string $label, string $severity, array $counts, string $unit, string $why, array $action, ?int $groupWide = null) use (&$checks, &$people) {
            $count = $groupWide ?? array_sum($counts);
            $sample = [];
            foreach (array_slice(array_keys($counts), 0, 10) as $id) {
                if (isset($people[$id])) {
                    $sample[] = ['employee_uuid' => $people[$id]->uuid, 'employee_number' => $people[$id]->employee_number, 'name' => $people[$id]->display_name, 'count' => $counts[$id]];
                }
            }
            $checks[] = ['key' => $key, 'label' => $label, 'severity' => $severity, 'state' => $count === 0 ? 'resolved' : 'open', 'count' => $count, 'unit' => $unit, 'affected_employees' => count($counts),
                'sample' => $sample, 'why' => $why, 'action' => $action, '_ids' => array_keys($counts)];
        };
        $nav = fn (string $label, string $href) => ['label' => $label, 'kind' => 'navigate', 'href' => $href];
        $count = fn ($rows) => collect($rows)->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all();

        if (! $period) {
            $add('payroll_period', 'Payroll period exists', 'blocking', [], 'periods', 'No open payroll period was found for this group, so nothing can be processed.', $nav('Create the next period', '/payroll/periods'), 1);
        } else {
            $roster = $this->roster($viewer, $group, $period, $from, $to);
            $people = collect($roster)->keyBy('id')->map(fn ($e) => (object) $e);
            $ids = array_keys($people->all());
            $idsIn = $ids ?: [0];
            $tenant = PayrollOps::tenantId();

            if (PayrollOps::moduleEnabled($viewer, 'attendance')) {
                $rows = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $idsIn)->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('attendance_processed', 'Attendance processed', 'blocking', array_diff_key(array_fill_keys($ids, 1), $rows), 'employees', 'These employees have no processed attendance in the period.', $nav('Process attendance', '/attendance/time-office/processing'));
                $open = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $idsIn)->whereNotIn('finalization', ['approved', 'locked'])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('attendance_finalized', 'Attendance finalized', PayrollSettings::get('inputs.attendance_pending_policy', 'block') === 'block' ? 'blocking' : 'warning', $count($open), 'employee-days', 'Pay must rely on approved or locked attendance only.', $nav('Review attendance', '/attendance/time-office/approvals'));
                $unlocked = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $idsIn)->where('finalization', 'approved')->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('attendance_locked', 'Attendance locked', 'warning', $count($unlocked), 'employee-days', 'Approved days can still change until the period is locked.', $nav('Lock attendance', '/attendance/time-office/locks'));
                $exc = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $idsIn)->whereIn('status', ['open', 'acknowledged'])->whereIn('severity', ['action_required', 'critical'])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('attendance_exceptions', 'Attendance exceptions resolved', 'blocking', $count($exc), 'exceptions', 'Open action-required exceptions can change payable days.', $nav('Resolve exceptions', '/attendance/time-office/exceptions'));
            } else {
                $skipped[] = 'attendance';
            }
            if (PayrollOps::moduleEnabled($viewer, 'leave')) {
                $pending = DB::table('leave_requests')->where('tenant_id', $tenant)->where('status', 'pending')->where('start_date', '<=', $to)->where('end_date', '>=', $from)->whereIn('employee_id', $idsIn)->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('leave_finalized', 'Leave finalized', 'blocking', $count($pending), 'requests', 'Pending leave requests can change unpaid days.', $nav('Decide leave requests', '/leave/approvals'));
                $locked = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->where('start_date', '<=', $from)->where('end_date', '>=', $to)->exists();
                $add('leave_locked', 'Leave period locked', 'warning', [], 'periods', 'The leave period is not locked, so leave may still change.', $nav('Lock leave', '/leave/locks'), $locked ? 0 : 1);
                if (PayrollSettings::get('inputs.require_leave_batch', true)) {
                    $batch = DB::table('leave_payroll_lines')->where('tenant_id', $tenant)->where('period_start', '<=', $to)->where('period_end', '>=', $from)->exists();
                    $add('leave_batch', 'Leave payroll batch published', 'blocking', [], 'batches', 'Payroll reads an immutable published leave batch for the period.', $nav('Publish leave batch', '/leave/payroll'), $batch ? 0 : 1);
                }
            } else {
                $skipped[] = 'leave';
            }

            $comp = DB::table('employee_compensations')->where('tenant_id', $tenant)->whereIn('employee_id', $idsIn)->where('status', 'approved')->where('effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->get(['id', 'employee_id', 'currency', 'base_pay']);
            $with = $comp->pluck('employee_id')->unique()->all();
            $add('compensation_missing', 'Approved compensation', 'blocking', array_fill_keys(array_diff($ids, $with), 1), 'employees', 'No approved compensation covers the period.', $nav('Assign compensation', '/payroll/compensation'));
            $lines = DB::table('compensation_lines')->whereIn('compensation_id', $comp->pluck('id')->all() ?: [0])->selectRaw('compensation_id, count(*) as c')->groupBy('compensation_id')->pluck('c', 'compensation_id');
            $incomplete = [];
            $conflict = [];
            foreach ($comp as $c) {
                if (($lines[$c->id] ?? 0) === 0 && $c->base_pay === null) {
                    $incomplete[(int) $c->employee_id] = 1;
                }
                if ($c->currency !== null && $c->currency !== $group->currency) {
                    $conflict[(int) $c->employee_id] = 1;
                }
            }
            $add('compensation_incomplete', 'Compensation complete', 'blocking', $incomplete, 'employees', 'Compensation has neither components nor a base pay.', $nav('Complete compensation', '/payroll/compensation'));
            $add('currency_conflict', 'Compensation currency matches the group', 'blocking', $conflict, 'employees', 'Currencies are never mixed in one payroll group.', $nav('Fix currency', '/payroll/compensation'));
            $pendingComp = DB::table('employee_compensations')->where('tenant_id', $tenant)->whereIn('employee_id', $idsIn)->where('status', 'pending')->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
            $add('compensation_pending', 'Compensation approvals', 'warning', $count($pendingComp), 'approvals', 'Compensation awaiting approval is not used by payroll.', $nav('Approve compensation', '/payroll/compensation'));

            $persons = DB::table('employees')->whereIn('id', $idsIn)->pluck('person_id', 'id');
            $banked = DB::table('employee_bank_accounts')->where('tenant_id', $tenant)->where('status', 'active')->whereIn('person_id', $persons->values()->all() ?: [0])->where(fn ($w) => $w->whereNull('effective_from')->orWhere('effective_from', '<=', $to))->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->pluck('person_id')->flip();
            $noBank = [];
            foreach ($ids as $id) {
                if (! isset($banked[$persons[$id] ?? 0])) {
                    $noBank[$id] = 1;
                }
            }
            $add('bank_missing', 'Bank details', 'blocking', $noBank, 'employees', 'Payment cannot be prepared without an active bank account.', $nav('Add bank details', '/people'));

            $this->packChecks($group, $to, $ids, $persons, $add, $nav);

            $inputs = DB::table('payroll_inputs')->where('tenant_id', $tenant)->where('period_id', $period->id)->whereIn('status', ['draft', 'pending'])->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
            $add('inputs_pending', 'Payroll inputs approved', 'blocking', $count($inputs), 'inputs', 'Pending inputs are not used by the calculation.', $nav('Approve inputs', '/payroll/inputs'));
            $adj = DB::table('payroll_adjustments')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $idsIn)->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
            $add('adjustments_pending', 'Adjustments approved', 'warning', $count($adj), 'adjustments', 'Adjustments awaiting approval are not applied.', $nav('Approve adjustments', '/payroll/adjustments'));
            foreach (['blocking', 'critical'] as $sev) {
                $base = DB::table('payroll_validation_issues')->where('tenant_id', $tenant)->where('period_id', $period->id)->where('severity', $sev)->whereIn('status', ['open', 'acknowledged']);
                $perEmployee = (clone $base)->whereNotNull('employee_id')->selectRaw('employee_id, count(*) as c')->groupBy('employee_id')->pluck('c', 'employee_id')->all();
                $add('payroll_exceptions_'.$sev, 'Unresolved payroll exceptions ('.$sev.')', $sev, $count($perEmployee), 'issues', 'Validation issues raised by payroll runs.', $nav('Open validations', '/payroll/validations'), (clone $base)->count());
            }

            $cutoffs = array_filter(['attendance' => substr((string) $period->getRawOriginal('attendance_cutoff'), 0, 10) ?: null, 'leave' => substr((string) $period->getRawOriginal('leave_cutoff'), 0, 10) ?: null, 'input' => substr((string) $period->getRawOriginal('input_cutoff'), 0, 10) ?: null]);
            $passed = array_filter($cutoffs, fn ($d) => $d < $today);
            $openSources = collect($checks)->filter(fn ($c) => $c['state'] === 'open' && in_array($c['key'], ['attendance_finalized', 'leave_finalized', 'inputs_pending'], true))->sum('count');
            $add('cutoff_risk', 'Cutoffs met', 'critical', [], 'cutoffs', 'A cutoff date has passed while source data is still unresolved.', $nav('Resolve open inputs', '/payroll/readiness'), ($passed && $openSources) ? count($passed) : 0);
        }
        $run = $period ? DB::table('payroll_runs')->where('period_id', $period->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->first(['uuid', 'status', 'run_type', 'employees_total', 'employees_failed']) : null;

        $worst = 0;
        $blocked = [];
        $counts = ['blocking' => 0, 'warning' => 0, 'critical' => 0, 'information' => 0];
        foreach ($checks as $c) {
            if ($c['state'] !== 'open') {
                continue;
            }
            $worst = max($worst, self::RANK[$c['severity']]);
            $counts[$c['severity']]++;
            if (self::RANK[$c['severity']] >= 2) {
                foreach ($c['_ids'] as $id) {
                    $blocked[$id][] = $c['key'];
                }
            }
        }
        $counts['blocking'] += $counts['critical'];
        $processing = $run && in_array($run->status, ['calculating', 'validating'], true);
        $state = $worst >= 2 ? 'blocked' : ($processing ? 'processing' : (($worst === 1 || $skipped) ? 'review_required' : 'ready'));
        $expected = count($roster);
        $ready = $expected - count($blocked);
        $public = array_map(function ($c) {
            unset($c['_ids']);

            return $c;
        }, $checks);

        return ['group' => ['uuid' => $group->uuid, 'name' => $group->name, 'code' => $group->code, 'currency' => $group->currency, 'country' => $group->country],
            'period' => $period ? ['uuid' => $period->uuid, 'label' => $period->label, 'start' => $from, 'end' => $to, 'state' => $period->state] : null,
            'run' => $run ? ['uuid' => $run->uuid, 'status' => $run->status, 'run_type' => $run->run_type, 'employees_failed' => (int) $run->employees_failed] : null,
            'state' => $state, 'status' => strtoupper($state), 'rule' => 'State is derived from explicit open conditions only; there is no score.',
            'employees_expected' => $expected, 'employees_ready' => $ready, 'percent_ready' => $expected ? round($ready / $expected * 100, 1) : null,
            'completeness' => ['conditions_resolved' => count(array_filter($public, fn ($c) => $c['state'] === 'resolved')), 'conditions_total' => count($public)],
            'counts' => $counts, 'checks' => $public, 'skipped_modules' => $skipped, '_roster' => $roster, '_blocked' => $blocked];
    }

    private function period(PayrollGroup $group, ?string $uuid, string $today): ?PayrollPeriod
    {
        $q = PayrollPeriod::where('payroll_group_id', $group->id);
        if ($uuid) {
            return (clone $q)->where('uuid', $uuid)->first();
        }

        return (clone $q)->where('period_start', '<=', $today)->where('period_end', '>=', $today)->first()
            ?? (clone $q)->whereNotIn('state', ['closed', 'paid'])->orderByDesc('period_end')->first();
    }

    /** @return array<int,array<string,mixed>> */
    private function roster(User $viewer, PayrollGroup $group, object $period, string $from, string $to): array
    {
        $q = PayrollOps::employees($viewer, PayrollOps::VIEW)->where('e.joined_on', '<=', $to)->where(fn ($w) => $w->whereNull('e.separated_on')->orWhere('e.separated_on', '>=', $from))->whereNotIn('e.status', ['preboarding']);
        $q->where(function ($w) use ($group, $from, $to, $period) {
            $w->whereExists(fn ($s) => $s->from('employee_policy_references as r')->whereColumn('r.employee_id', 'e.id')->where('r.kind', 'payroll_group')->where('r.reference_id', $group->id)
                ->where('r.effective_from', '<=', $to)->where(fn ($x) => $x->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $from)))
                ->orWhereExists(fn ($s) => $s->from('payroll_run_employees as pre')->join('payroll_runs as pr', 'pr.id', '=', 'pre.run_id')->whereColumn('pre.employee_id', 'e.id')->where('pr.period_id', $period->id)->where('pre.status', '!=', 'excluded'));
        });

        return $q->orderBy('e.id')->get(['e.id', 'e.uuid', 'e.employee_number', 'e.person_id', 'p.display_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'uuid' => $r->uuid, 'employee_number' => $r->employee_number, 'display_name' => $r->display_name, 'person_id' => (int) $r->person_id])->all();
    }

    private function packChecks(PayrollGroup $group, string $to, array $ids, $persons, \Closure $add, \Closure $nav): void
    {
        if (! $group->country_pack_id) {
            $add('pack_attached', 'Statutory pack attached', 'information', [], 'groups', 'No country pack is attached: no statutory amounts will be calculated.', $nav('Attach a pack', '/payroll/groups'), 1);

            return;
        }
        $pack = DB::table('payroll_country_packs')->where('id', $group->country_pack_id)->first();
        $version = DB::table('payroll_country_pack_versions')->where('pack_id', $group->country_pack_id)->where('status', 'published')->where('effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $to))->orderByDesc('version')->first();
        $add('pack_enabled', 'Country pack enabled', 'blocking', [], 'packs', 'The pack attached to this group is not enabled.', $nav('Open country packs', '/payroll/country-packs'), ($pack && $pack->status === 'enabled') ? 0 : 1);
        $add('pack_version', 'Published pack version in force', 'blocking', [], 'versions', 'No published pack version covers the end of the period.', $nav('Publish a version', '/payroll/country-packs'), $version ? 0 : 1);
        $add('pack_verified', 'Pack verified', 'blocking', [], 'versions', 'Pack content has not been verified by an expert.', $nav('Verify the pack', '/payroll/country-packs'), ($version && ! $version->verified_at) ? 1 : 0);
        if ($version && $version->identifiers) {
            $required = collect(json_decode($version->identifiers, true) ?: [])->filter(fn ($i) => is_array($i) && ! empty($i['required']) && ! empty($i['key']))->pluck('key')->all();
            if ($required) {
                $have = DB::table('identity_documents')->whereIn('person_id', $persons->values()->all() ?: [0])->whereIn('type', $required)->get(['person_id', 'type'])->groupBy('person_id');
                $missing = [];
                foreach ($ids as $id) {
                    $n = count(array_diff($required, ($have[$persons[$id] ?? 0] ?? collect())->pluck('type')->all()));
                    if ($n) {
                        $missing[$id] = $n;
                    }
                }
                $add('statutory_identifiers', 'Statutory identifiers', 'blocking', $missing, 'identifiers', 'Required statutory identifiers are missing.', $nav('Add identifiers', '/people'));
            }
        }
    }
}
