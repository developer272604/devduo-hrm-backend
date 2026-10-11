<?php
namespace App\Domain\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Assets\AssetHistoryService;
use App\Domain\Assets\AssetService;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use App\Support\Spreadsheet\TabularWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only operations reports. Spend/violation reports are limited to the viewer's employee scope; asset reports need tenant-wide asset management. Exports are masked and purpose-bound. */
final class OperationsReportService {
    public const REPORTS = ['spend' => 'expenses', 'violations' => 'expenses', 'asset-utilization' => 'assets', 'asset-depreciation' => 'assets'];
    public function __construct(private DataScope $scope, private AssetService $assets, private AssetHistoryService $history) {}

    public function build(User $user, string $report, array $p, bool $mask = false): array {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless(in_array(self::REPORTS[$report], $user->tenant->settings['modules'] ?? [], true), 404);
        return match ($report) {
            'spend' => $this->spend($user, $p),
            'violations' => $this->violations($user, $p, $mask),
            'asset-utilization' => $this->utilization($user),
            'asset-depreciation' => $this->depreciation($user, $p),
        };
    }
    private function period(array $p): array {
        $to = $p['to'] ?? TenantClock::today(); $from = $p['from'] ?? CarbonImmutable::parse($to)->subDays(89)->toDateString();
        if ($from > $to) throw ValidationException::withMessages(['from' => 'The start date must not be after the end date.']);
        return [$from, $to];
    }
    private function claims(User $user, string $alias = 'expense_claims') {
        return $this->scope->constrain(DB::table('expense_claims')->where('expense_claims.tenant_id', $user->tenant_id), $user, 'operations.reports.view', 'expense_claims.employee_id');
    }
    private function spend(User $user, array $p): array {
        [$from, $to] = $this->period($p); $group = ($p['group'] ?? 'category') === 'department' ? 'department' : 'category';
        $statuses = array_values(array_intersect(explode(',', $p['status'] ?? 'approved,paid'), ['submitted', 'approved', 'paid', 'rejected'])) ?: ['approved', 'paid'];
        $q = $this->claims($user)->join('expense_lines', 'expense_lines.claim_id', '=', 'expense_claims.id')->join('expense_policies', 'expense_policies.id', '=', 'expense_claims.policy_id')
            ->whereIn('expense_claims.status', $statuses)->whereBetween('expense_lines.spent_on', [$from, $to]);
        if ($group === 'department') {
            $q->leftJoin('departments', 'departments.id', '=', 'expense_claims.department_id')->groupBy('expense_claims.currency', 'expense_claims.minor_units', 'expense_claims.department_id', 'departments.name')
                ->selectRaw('expense_claims.currency as currency, expense_claims.minor_units as scale, departments.name as label, count(distinct expense_claims.id) as claims, count(distinct expense_claims.employee_id) as people, sum(expense_lines.amount_minor) as total');
        } else {
            $q->groupBy('expense_claims.currency', 'expense_claims.minor_units', DB::raw('coalesce(expense_lines.category, expense_policies.category)'))
                ->selectRaw('expense_claims.currency as currency, expense_claims.minor_units as scale, coalesce(expense_lines.category, expense_policies.category) as label, count(distinct expense_claims.id) as claims, count(distinct expense_claims.employee_id) as people, sum(expense_lines.amount_minor) as total');
        }
        $rows = $q->orderBy('currency')->orderByDesc('total')->get()->map(fn ($r) => [$group => $r->label ?: 'Unassigned', 'currency' => $r->currency, 'claims' => (int) $r->claims, 'people' => (int) $r->people, 'total' => Money::format((int) $r->total, (int) $r->scale)])->all();
        return ['title' => 'Spend by '.$group, 'meta' => ['from' => $from, 'to' => $to, 'statuses' => $statuses, 'note' => 'Totals are per currency; currencies are never added together.'],
            'columns' => [$group => ucfirst($group), 'currency' => 'Currency', 'claims' => 'Claims', 'people' => 'People', 'total' => 'Total'], 'rows' => $rows];
    }
    private function violations(User $user, array $p, bool $mask): array {
        [$from, $to] = $this->period($p);
        $q = $this->claims($user)->join('expense_violations', 'expense_violations.claim_id', '=', 'expense_claims.id')->join('employees', 'employees.id', '=', 'expense_claims.employee_id')->join('people', 'people.id', '=', 'employees.person_id')
            ->leftJoin('departments', 'departments.id', '=', 'expense_claims.department_id')->whereBetween(DB::raw('date(expense_violations.created_at)'), [$from, $to]);
        if (! empty($p['severity']) && in_array($p['severity'], ['warn', 'block', 'escalate'], true)) $q->where('expense_violations.severity', $p['severity']);
        $rows = $q->orderByDesc('expense_violations.id')->limit(1000)->get(['expense_violations.code', 'expense_violations.severity', 'expense_violations.message', 'expense_violations.created_at', 'expense_claims.uuid as claim_uuid', 'expense_claims.status as claim_status',
            'departments.name as department', 'people.legal_first_name as first', 'people.legal_last_name as last', 'employees.uuid as employee_uuid'])->map(function ($r) use ($mask) {
            $initials = mb_strtoupper(mb_substr((string) $r->first, 0, 1)).'. '.mb_strtoupper(mb_substr((string) $r->last, 0, 1)).'.';
            return ['date' => substr((string) $r->created_at, 0, 10), 'code' => $r->code, 'severity' => $r->severity, 'claim_status' => $r->claim_status, 'department' => $r->department ?: 'Unassigned',
                'employee' => $mask ? $initials : trim($r->first.' '.$r->last), 'reference' => substr(hash('sha256', ($mask ? 'claim:' : '').$r->claim_uuid), 0, 10), 'message' => $mask ? null : $r->message];
        })->all();
        $cols = ['date' => 'Date', 'code' => 'Violation', 'severity' => 'Action', 'claim_status' => 'Claim status', 'department' => 'Department', 'employee' => 'Employee', 'reference' => 'Reference'] + ($mask ? [] : ['message' => 'Detail']);
        if ($mask) $rows = array_map(fn ($r) => array_diff_key($r, ['message' => 1]), $rows);
        return ['title' => 'Policy violations', 'meta' => ['from' => $from, 'to' => $to, 'masked' => $mask], 'columns' => $cols, 'rows' => $rows];
    }
    private function utilization(User $user): array {
        abort_unless($this->assets->manages($user), 403);
        $today = TenantClock::today(); $soon = CarbonImmutable::parse($today)->addDays(90)->toDateString(); $rows = [];
        foreach (DB::table('assets')->where('tenant_id', $user->tenant_id)->get(['id', 'category', 'status', 'warranty_until', 'updated_at']) as $a) {
            $r = &$rows[$a->category]; $r ??= ['category' => $a->category, 'total' => 0, 'assigned' => 0, 'available' => 0, 'repair' => 0, 'lost' => 0, 'disposed' => 0, 'warranty_expiring' => 0, 'idle_90d' => 0];
            $r['total']++; $r[$a->status] = ($r[$a->status] ?? 0) + 1;
            if ($a->warranty_until && $a->warranty_until >= $today && $a->warranty_until <= $soon) $r['warranty_expiring']++;
            if ($a->status === 'available' && substr((string) $a->updated_at, 0, 10) <= CarbonImmutable::parse($today)->subDays(90)->toDateString()) $r['idle_90d']++;
            unset($r);
        }
        $out = array_map(function ($r) { $active = $r['total'] - $r['disposed'] - $r['lost']; $r['utilization_pct'] = $active > 0 ? number_format(intdiv($r['assigned'] * 10000 + intdiv($active, 2), $active) / 100, 1, '.', '') : '0.0'; return $r; }, array_values($rows));
        return ['title' => 'Asset utilization', 'meta' => ['as_of' => $today, 'note' => 'Utilization = assigned / (total - disposed - lost).'], 'columns' => ['category' => 'Category', 'total' => 'Total', 'assigned' => 'Assigned', 'available' => 'Available', 'repair' => 'In repair',
            'lost' => 'Lost', 'disposed' => 'Disposed', 'idle_90d' => 'Idle 90+ days', 'warranty_expiring' => 'Warranty ends within 90 days', 'utilization_pct' => 'Utilization %'], 'rows' => $out];
    }
    private function depreciation(User $user, array $p): array {
        $d = $this->history->depreciation($user, $p['as_of'] ?? TenantClock::today());
        return ['title' => 'Asset depreciation (straight-line)', 'meta' => ['as_of' => $d['as_of'], 'totals' => $d['totals'], 'note' => $d['note']],
            'columns' => ['identifier' => 'Identifier', 'name' => 'Name', 'category' => 'Category', 'status' => 'Status', 'currency' => 'Currency', 'cost' => 'Cost', 'accumulated' => 'Accumulated', 'book_value' => 'Book value'],
            'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['identifier', 'name', 'category', 'status', 'currency', 'cost', 'accumulated', 'book_value'])), $d['rows'])];
    }

    /** Masked, purpose-bound export. Returns [path, filename]. */
    public function export(User $user, string $report, array $p, string $format, string $purpose): array {
        $r = $this->build($user, $report, $p, true);
        $path = tempnam(sys_get_temp_dir(), 'ops'); $w = TabularWriter::open($format, $path, array_values($r['columns']));
        foreach ($r['rows'] as $row) $w->write(array_map(fn ($k) => $row[$k] ?? null, array_keys($r['columns'])));
        $w->close();
        Audit::record('operations.report_exported', 'operations', 'report:'.$report, ['purpose' => mb_substr($purpose, 0, 300), 'format' => $format, 'rows' => count($r['rows']), 'masked' => true]);
        return [$path, $report.'-'.TenantClock::today().'.'.$format];
    }
}
