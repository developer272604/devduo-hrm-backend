<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Leave\Support\Units;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Employee Time Story: one chronological, factual narrative of an employee's leave life (requests, decisions, ledger movements,
 * lock/transfer/comp-off events) so HR can answer "what happened and why" without opening five screens.
 * Facts only, each with its source. Reasons and documents are never included; sensitive (medical) leave appears as "Leave"
 * unless the viewer holds leave.medical.view.
 */
final class TimeStory
{
    public const LIMIT = 300;

    /** @return array<string,mixed> */
    public function build(User $viewer, object $employee, ?string $from = null, ?string $to = null): array
    {
        $tenant = LeaveOps::tenantId();
        $medical = LeaveOps::medical($viewer);
        $id = (int) $employee->id;
        $in = fn (string $col) => fn ($q) => $q->when($from, fn ($w) => $w->where($col, '>=', $from))->when($to, fn ($w) => $w->where($col, '<=', $to.' 23:59:59'));
        $events = [];
        $day = fn ($v) => substr((string) $v, 0, 10);

        $requests = DB::table('leave_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', $tenant)->where('r.employee_id', $id)->where('r.status', '!=', 'draft')
            ->when($from, fn ($q) => $q->where('r.end_date', '>=', $from))->when($to, fn ($q) => $q->where('r.start_date', '<=', $to))->get(['r.id', 'r.uuid', 'r.status', 'r.start_date', 'r.end_date', 'r.charged_units', 'r.is_sensitive', 't.name', 't.is_sensitive as type_sensitive']);
        $hide = [];
        foreach ($requests as $r) {
            $name = ($r->is_sensitive || $r->type_sensitive) && ! $medical ? 'Leave' : $r->name;
            $hide[$r->id] = $name;
            $events[] = ['date' => $day($r->start_date), 'kind' => 'leave', 'title' => $name.' '.$day($r->start_date).($r->end_date !== $r->start_date ? ' to '.$day($r->end_date) : ''), 'detail' => ucfirst($r->status).', '.Units::normalize($r->charged_units).' charged', 'ref' => $r->uuid, 'source' => 'leave_requests'];
        }
        $actions = DB::table('leave_request_actions as a')->join('leave_requests as r', 'r.id', '=', 'a.request_id')->where('a.tenant_id', $tenant)->where('r.employee_id', $id)->tap($in('a.created_at'))
            ->orderBy('a.id')->get(['a.request_id', 'a.action', 'a.to_status', 'a.created_at', 'r.uuid']);
        foreach ($actions as $a) {
            $events[] = ['date' => $day($a->created_at), 'kind' => 'decision', 'title' => ucfirst(str_replace('_', ' ', $a->action)).' - '.($hide[$a->request_id] ?? 'Leave'), 'detail' => $a->to_status ? 'Now '.$a->to_status : null, 'ref' => $a->uuid, 'source' => 'leave_request_actions'];
        }
        $entries = DB::table('leave_ledger_entries as l')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')->where('l.tenant_id', $tenant)->where('l.employee_id', $id)
            ->whereNotIn('l.entry_type', ['reservation', 'reservation_release'])->when($from, fn ($q) => $q->where('l.effective_date', '>=', $from))->when($to, fn ($q) => $q->where('l.effective_date', '<=', $to))
            ->get(['l.uuid', 'l.entry_type', 'l.units', 'l.effective_date', 't.code', 't.is_sensitive']);
        foreach ($entries as $e) {
            $code = $e->is_sensitive && ! $medical ? 'Restricted' : $e->code;
            $events[] = ['date' => $day($e->effective_date), 'kind' => 'ledger', 'title' => ucfirst(str_replace('_', ' ', $e->entry_type)).' '.Units::normalize($e->units).' ('.$code.')', 'detail' => null, 'ref' => $e->uuid, 'source' => 'leave_ledger_entries'];
        }
        foreach (DB::table('leave_comp_off_credits')->where('tenant_id', $tenant)->where('employee_id', $id)->when($from, fn ($q) => $q->where('work_date', '>=', $from))->when($to, fn ($q) => $q->where('work_date', '<=', $to))->get(['uuid', 'work_date', 'units', 'status', 'source_type']) as $c) {
            $events[] = ['date' => $day($c->work_date), 'kind' => 'comp_off', 'title' => 'Comp-off credit '.Units::normalize($c->units).' ('.str_replace('_', ' ', $c->source_type).')', 'detail' => ucfirst($c->status), 'ref' => $c->uuid ?? null, 'source' => 'leave_comp_off_credits'];
        }
        foreach (DB::table('leave_transfers')->where('tenant_id', $tenant)->where('employee_id', $id)->get(['uuid', 'trigger', 'effective_date', 'status', 'balance_mode']) as $t) {
            $events[] = ['date' => $day($t->effective_date), 'kind' => 'lifecycle', 'title' => ucfirst($t->trigger).' leave handling ('.$t->balance_mode.')', 'detail' => ucfirst($t->status), 'ref' => $t->uuid, 'source' => 'leave_transfers'];
        }
        $locks = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->get(['uuid', 'scope_type', 'scope_id', 'start_date', 'end_date']);
        $guard = app(\App\Domain\Leave\Contracts\LeaveLockGuard::class);
        foreach ($locks as $l) {
            if ($guard->isLocked($id, $day($l->start_date))) {
                $events[] = ['date' => $day($l->start_date), 'kind' => 'lock', 'title' => 'Period locked '.$day($l->start_date).' to '.$day($l->end_date), 'detail' => null, 'ref' => $l->uuid, 'source' => 'leave_locks'];
            }
        }

        usort($events, fn ($a, $b) => [$b['date'], $a['kind']] <=> [$a['date'], $b['kind']]);
        $total = count($events);

        return ['employee_uuid' => $employee->uuid, 'from' => $from, 'to' => $to, 'total' => $total, 'truncated' => $total > self::LIMIT, 'events' => array_slice($events, 0, self::LIMIT)];
    }
}
