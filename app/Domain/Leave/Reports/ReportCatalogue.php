<?php

namespace App\Domain\Leave\Reports;

use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave reports (one catalogue: base query + columns + date column). Always tenant + DataScope limited
 * (leave.reports.view / leave.manage), whitelisted sorting, page size <= 100. Reasons, documents and request text are never
 * reported; sensitive (medical) leave types are shown as "Restricted" unless the viewer holds leave.medical.view.
 * Balance columns are computed by BalanceService from the ledger (same numbers as My Leave), never from a stored value.
 */
final class ReportCatalogue
{
    public const PERMISSIONS = ['leave.reports.view', 'leave.manage'];

    public const FILTERS = ['q', 'employee', 'employee_number', 'department_id', 'location_id', 'legal_entity_id', 'manager', 'leave_type', 'status', 'from', 'to', 'pay_class', 'batch_key'];

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        $type = 't.code';

        return [
            'balances' => ['title' => 'Leave balances', 'date' => 'a.period_start', 'type' => $type, 'status' => 'a.status', 'id' => 'a.id', 'default_sort' => 'period_start', 'sorts' => ['period_start', 'leave_type', 'status'], 'decorate' => 'balances',
                'cols' => ['leave_type' => $type, 'period_start' => 'a.period_start', 'period_end' => 'a.period_end', 'status' => 'a.status', 'negative_limit' => 'a.negative_limit', '_sensitive' => 't.is_sensitive', '_account' => 'a.id'],
                'base' => fn (Builder $q) => $q->from('leave_accounts as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->join('leave_types as t', 't.id', '=', 'a.leave_type_id')],
            'ledger' => ['title' => 'Leave ledger', 'date' => 'l.effective_date', 'type' => $type, 'id' => 'l.id', 'default_sort' => 'effective_date', 'sorts' => ['effective_date', 'leave_type', 'entry_type'],
                'cols' => ['effective_date' => 'l.effective_date', 'leave_type' => $type, 'entry_type' => 'l.entry_type', 'bucket' => 'l.bucket', 'units' => 'l.units', 'expires_on' => 'l.expires_on', 'posted_at' => 'l.posted_at', '_sensitive' => 't.is_sensitive'],
                'base' => fn (Builder $q) => $q->from('leave_ledger_entries as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')],
            'requests' => ['title' => 'Leave requests', 'date' => 'r.start_date', 'type' => $type, 'status' => 'r.status', 'id' => 'r.id', 'default_sort' => 'start_date', 'sorts' => ['start_date', 'status', 'leave_type', 'charged_units'],
                'cols' => ['leave_type' => $type, 'status' => 'r.status', 'start_date' => 'r.start_date', 'end_date' => 'r.end_date', 'portion' => 'r.portion', 'requested_units' => 'r.requested_units', 'charged_units' => 'r.charged_units',
                    'submitted_at' => 'r.submitted_at', 'decided_at' => 'r.decided_at', 'confidence' => 'r.confidence', '_sensitive' => 't.is_sensitive'],
                'base' => fn (Builder $q) => $q->from('leave_requests as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.status', '!=', 'draft')],
            'utilization' => ['title' => 'Leave utilization (charged days)', 'date' => 's.leave_date', 'type' => $type, 'id' => 's.id', 'default_sort' => 'date', 'sorts' => ['date', 'leave_type', 'units'],
                'cols' => ['date' => 's.leave_date', 'leave_type' => $type, 'portion' => 's.portion', 'units' => 's.units', 'pay_class' => 's.pay_class', 'day_kind' => 's.day_kind', '_sensitive' => 't.is_sensitive'], 'pay_class' => 's.pay_class',
                'base' => fn (Builder $q) => $q->from('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->join('employees as e', 'e.id', '=', 's.employee_id')->join('leave_types as t', 't.id', '=', 's.leave_type_id')
                    ->where('r.status', 'approved')->where('s.status', 'active')->where('s.charged', true)],
            'adjustments' => ['title' => 'Balance adjustments', 'date' => 'j.created_at', 'type' => $type, 'status' => 'j.status', 'id' => 'j.id', 'default_sort' => 'created_at', 'sorts' => ['created_at', 'status', 'units'],
                'cols' => ['created_at' => 'j.created_at', 'leave_type' => $type, 'kind' => 'j.kind', 'units' => 'j.units', 'status' => 'j.status', 'decided_at' => 'j.decided_at', '_sensitive' => 't.is_sensitive'],
                'base' => fn (Builder $q) => $q->from('leave_adjustments as j')->join('employees as e', 'e.id', '=', 'j.employee_id')->join('leave_accounts as a', 'a.id', '=', 'j.account_id')->join('leave_types as t', 't.id', '=', 'a.leave_type_id')],
            'expiring' => ['title' => 'Balances expiring', 'date' => 'l.expires_on', 'type' => $type, 'id' => 'l.id', 'default_sort' => 'expires_on', 'sorts' => ['expires_on', 'leave_type'], 'decorate' => 'expiring',
                'cols' => ['expires_on' => 'l.expires_on', 'leave_type' => $type, 'granted_units' => 'l.units', 'bucket' => 'l.bucket', '_sensitive' => 't.is_sensitive', '_account' => 'l.account_id', '_entry' => 'l.id'],
                'base' => fn (Builder $q) => $q->from('leave_ledger_entries as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')
                    ->whereNotNull('l.expires_on')->where('l.units', '>', 0)->whereNull('l.reverses_entry_id')],
            'comp_off' => ['title' => 'Comp-off credits', 'date' => 'c.work_date', 'status' => 'c.status', 'id' => 'c.id', 'default_sort' => 'work_date', 'sorts' => ['work_date', 'status', 'units'],
                'cols' => ['work_date' => 'c.work_date', 'source_type' => 'c.source_type', 'units' => 'c.units', 'status' => 'c.status', 'valid_from' => 'c.valid_from', 'expires_on' => 'c.expires_on'],
                'base' => fn (Builder $q) => $q->from('leave_comp_off_credits as c')->join('employees as e', 'e.id', '=', 'c.employee_id')],
            'encashment' => ['title' => 'Encashment and settlement references (units only)', 'date' => 'x.as_of', 'type' => $type, 'status' => 'x.status', 'id' => 'x.id', 'default_sort' => 'as_of', 'sorts' => ['as_of', 'status', 'units'],
                'cols' => ['as_of' => 'x.as_of', 'leave_type' => $type, 'kind' => 'x.kind', 'units' => 'x.units', 'status' => 'x.status', 'payroll_reference' => 'x.payroll_reference', '_sensitive' => 't.is_sensitive'],
                'base' => fn (Builder $q) => $q->from('leave_settlement_references as x')->join('employees as e', 'e.id', '=', 'x.employee_id')->join('leave_types as t', 't.id', '=', 'x.leave_type_id')],
            'payroll_lines' => ['title' => 'Payroll contract lines (units only)', 'date' => 'pl.period_start', 'type' => $type, 'id' => 'pl.id', 'default_sort' => 'period_start', 'sorts' => ['period_start', 'pay_class', 'units'], 'pay_class' => 'pl.pay_class',
                'cols' => ['batch_key' => 'pl.batch_key', 'period_start' => 'pl.period_start', 'period_end' => 'pl.period_end', 'pay_class' => 'pl.pay_class', 'leave_type' => $type, 'units' => 'pl.units', 'late_adjustment' => 'pl.late_adjustment', 'cutoff_date' => 'pl.cutoff_date', '_sensitive' => 't.is_sensitive'],
                'base' => fn (Builder $q) => $q->from('leave_payroll_lines as pl')->join('employees as e', 'e.id', '=', 'pl.employee_id')->join('leave_types as t', 't.id', '=', 'pl.leave_type_id')],
        ];
    }

    public function exists(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function catalogue(): array
    {
        return collect($this->definitions())->map(fn ($d, $k) => ['key' => $k, 'title' => $d['title'], 'columns' => array_values(array_filter(array_keys($d['cols']), fn ($c) => ! str_starts_with($c, '_'))), 'sorts' => $d['sorts']])->values()->all();
    }

    /** Filtered, scoped query without ordering/paging. */
    public function query(User $user, string $key, array $f): Builder
    {
        $def = $this->definitions()[$key] ?? abort(404, 'That report does not exist.');
        $q = DB::query();
        $def['base']($q);
        $q->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', LeaveOps::tenantId());
        $q->whereIn('e.id', LeaveOps::employees($user, self::PERMISSIONS)->select('e.id'));
        $today = LeaveOps::today();
        $date = $def['date'];
        if (! empty($f['from'])) {
            $q->where($date, '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where($date, '<=', $f['to'].(str_ends_with($date, 'created_at') || str_ends_with($date, 'posted_at') ? ' 23:59:59' : ''));
        }
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('p.display_name', 'like', $term)->orWhere('e.employee_number', 'like', $term));
        }
        if (! empty($f['employee'])) {
            $q->where('e.uuid', $f['employee']);
        }
        if (! empty($f['employee_number'])) {
            $q->where('e.employee_number', $f['employee_number']);
        }
        foreach (['department_id', 'location_id', 'legal_entity_id'] as $dim) {
            if (! empty($f[$dim])) {
                $q->whereExists(fn ($s) => $s->from('employee_assignments as a2')->whereColumn('a2.employee_id', 'e.id')->where('a2.'.$dim, (int) $f[$dim])->where('a2.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a2.effective_to')->orWhere('a2.effective_to', '>=', $today)));
            }
        }
        if (! empty($f['manager'])) {
            $q->whereExists(function ($s) use ($f, $today) {
                $s->from('employee_reporting_relationships as rr')->join('employees as m', 'm.id', '=', 'rr.manager_id')->whereColumn('rr.employee_id', 'e.id')->where('rr.type', 'primary')
                    ->where('rr.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('rr.effective_to')->orWhere('rr.effective_to', '>=', $today))->where('m.uuid', $f['manager']);
            });
        }
        if (! empty($f['leave_type']) && isset($def['type'])) {
            $q->where($def['type'], $f['leave_type']);
        }
        if (! empty($f['status']) && isset($def['status'])) {
            $q->where($def['status'], $f['status']);
        }
        if (! empty($f['pay_class']) && isset($def['pay_class'])) {
            $q->where($def['pay_class'], $f['pay_class']);
        }
        if (! empty($f['batch_key']) && $key === 'payroll_lines') {
            $q->where('pl.batch_key', $f['batch_key']);
        }

        return $q;
    }

    private function select(string $key, Builder $q): Builder
    {
        $def = $this->definitions()[$key];
        $cols = ['e.uuid as employee_uuid', 'e.employee_number', 'p.display_name as employee_name', $def['id'].' as _id'];
        foreach ($def['cols'] as $alias => $expr) {
            $cols[] = $expr.' as '.$alias;
        }

        return $q->select($cols);
    }

    /** Masks sensitive types, adds derived columns, strips internal columns. @param array<int,array<string,mixed>> $rows */
    private function finish(User $user, string $key, array $rows): array
    {
        $def = $this->definitions()[$key];
        $medical = LeaveOps::medical($user);
        $balances = app(BalanceService::class);
        $today = LeaveOps::today();
        $lotCache = [];
        foreach ($rows as &$r) {
            if (($def['decorate'] ?? null) === 'balances') {
                $b = $balances->balance((int) $r['_account'], $today);
                foreach (['opening', 'accrued', 'carried', 'adjusted', 'consumed', 'pending', 'scheduled', 'expired', 'encashed', 'entitled', 'available'] as $c) {
                    $r[$c] = $b->{$c};
                }
            } elseif (($def['decorate'] ?? null) === 'expiring') {
                $acct = (int) $r['_account'];
                $lotCache[$acct] ??= collect($balances->lots($acct, $today))->keyBy('entry_id');
                $r['remaining_units'] = $lotCache[$acct][(int) $r['_entry']]['remaining'] ?? '0.0000';
            }
            foreach (['units', 'requested_units', 'charged_units', 'granted_units', 'negative_limit'] as $c) {
                if (isset($r[$c])) {
                    $r[$c] = Units::normalize($r[$c]);
                }
            }
            if (! empty($r['_sensitive']) && ! $medical && isset($r['leave_type'])) {
                $r['leave_type'] = 'Restricted';
            }
            unset($r['_sensitive'], $r['_account'], $r['_entry'], $r['_id']);
        }
        unset($r);

        return $rows;
    }

    /** @return array{data:array,meta:array} */
    public function run(User $user, string $key, array $f, ?string $sort, string $dir, int $page, int $perPage): array
    {
        $def = $this->definitions()[$key] ?? abort(404, 'That report does not exist.');
        $q = $this->query($user, $key, $f);
        $total = (clone $q)->count();
        $sort = in_array($sort, $def['sorts'], true) ? $sort : $def['default_sort'];
        $this->select($key, $q)->orderBy($def['cols'][$sort], $dir === 'asc' ? 'asc' : 'desc')->orderBy('e.id')->orderBy($def['id']);
        $rows = $this->finish($user, $key, $q->forPage($page, $perPage)->get()->map(fn ($r) => (array) $r)->all());

        return ['data' => $rows, 'meta' => ['report' => $key, 'title' => $def['title'], 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'sort' => $sort, 'dir' => $dir]];
    }

    /** Streams all rows for export in stable chunks. @return \Generator<int,array> */
    public function stream(User $user, string $key, array $f, int $limit = 100000): \Generator
    {
        $def = $this->definitions()[$key] ?? abort(404);
        $page = 1;
        $n = 0;
        while ($n < $limit) {
            $rows = $this->select($key, $this->query($user, $key, $f))->orderBy($def['cols'][$def['default_sort']])->orderBy('e.id')->orderBy($def['id'])->forPage($page++, 500)->get()->map(fn ($r) => (array) $r)->all();
            if ($rows === []) {
                return;
            }
            foreach ($this->finish($user, $key, $rows) as $r) {
                yield $r;
                $n++;
            }
        }
    }
}
