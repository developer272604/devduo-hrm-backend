<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Leave context for Employee 360: balances, upcoming/recent leave, confidence. Facts only, each with its source.
 * Shown to the employee, and to viewers whose data scope (leave.team.view / leave.manage / leave.reports.view) reaches the employee.
 * Sensitive (medical) leave appears as "Leave" without type or reason unless the viewer holds leave.medical.view. No reasons, ever.
 */
final class LeaveEmployee360Provider implements Employee360Provider
{
    private const VIEW = ['leave.team.view', 'leave.manage', 'leave.reports.view'];

    public function __construct(private DataScope $scope, private BalanceService $balances, private Confidence $confidence) {}

    public function key(): string
    {
        return 'leave';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        $allowed = $relation['self'] && $viewer->allows('leave.view');
        foreach (self::VIEW as $permission) {
            $allowed = $allowed || ($viewer->allows($permission) && $this->scope->canAccess($viewer, $employee, $permission));
        }
        if (! $allowed) {
            return [];
        }
        $tenant = LeaveOps::tenantId();
        $today = LeaveOps::today();
        $medical = LeaveOps::medical($viewer);
        $item = fn (string $key, string $label, mixed $value = null, ?string $detail = null, ?string $date = null) => array_filter(['key' => $key, 'label' => $label, 'value' => $value, 'detail' => $detail, 'date' => $date, 'source' => 'leave'], fn ($v) => $v !== null);
        $out = [];

        $balanceItems = [];
        foreach (LeaveAccount::query()->where('employee_id', $employee->id)->where('status', 'open')->where('period_start', '<=', $today)->where('period_end', '>=', $today)->get() as $a) {
            $type = DB::table('leave_types')->where('id', $a->leave_type_id)->first(['code', 'name', 'is_sensitive']);
            if ($type->is_sensitive && ! $medical) {
                continue;
            }
            $b = $this->balances->balance($a, $today);
            $balanceItems[] = $item('balance:'.$a->uuid, $type->name, Units::normalize($b->available), 'Used '.$b->consumed.', pending '.$b->pending.', scheduled '.$b->scheduled);
        }
        if ($balanceItems) {
            $out[] = ['key' => 'leave_balances', 'title' => 'Leave balances', 'order' => 50, 'source' => 'leave', 'items' => $balanceItems];
        }

        $rows = DB::table('leave_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', $tenant)->where('r.employee_id', $employee->id)->whereIn('r.status', ['pending', 'approved'])
            ->where('r.end_date', '>=', $today)->orderBy('r.start_date')->limit(5)->get(['r.uuid', 'r.status', 'r.start_date', 'r.end_date', 'r.charged_units', 'r.is_sensitive', 't.name', 't.is_sensitive as ts']);
        if ($rows->isNotEmpty()) {
            $out[] = ['key' => 'leave_upcoming', 'title' => 'Upcoming leave', 'order' => 51, 'source' => 'leave', 'items' => $rows->map(fn ($r) => $item('request:'.$r->uuid, ($r->is_sensitive || $r->ts) && ! $medical ? 'Leave' : $r->name,
                ucfirst($r->status), substr((string) $r->start_date, 0, 10).' to '.substr((string) $r->end_date, 0, 10).', '.Units::normalize($r->charged_units).' units', substr((string) $r->start_date, 0, 10)))->all()];
        }
        $recent = DB::table('leave_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', $tenant)->where('r.employee_id', $employee->id)->whereIn('r.status', ['approved', 'rejected', 'cancelled'])
            ->where('r.end_date', '<', $today)->orderByDesc('r.start_date')->limit(5)->get(['r.uuid', 'r.status', 'r.start_date', 'r.end_date', 'r.charged_units', 'r.is_sensitive', 't.name', 't.is_sensitive as ts']);
        if ($recent->isNotEmpty()) {
            $out[] = ['key' => 'leave_recent', 'title' => 'Recent leave', 'order' => 52, 'source' => 'leave', 'items' => $recent->map(fn ($r) => $item('request:'.$r->uuid, ($r->is_sensitive || $r->ts) && ! $medical ? 'Leave' : $r->name,
                ucfirst($r->status), substr((string) $r->start_date, 0, 10).' to '.substr((string) $r->end_date, 0, 10), substr((string) $r->start_date, 0, 10)))->all()];
        }
        if ($balanceItems || $rows->isNotEmpty() || $recent->isNotEmpty()) {
            $c = $this->confidence->forEmployee((int) $employee->id, $today);
            $out[] = ['key' => 'leave_confidence', 'title' => 'Leave confidence', 'order' => 53, 'source' => 'leave', 'items' => [$item('confidence', 'Leave data', $c['label'], $c['meaning'])]];
        }

        return $out;
    }
}
