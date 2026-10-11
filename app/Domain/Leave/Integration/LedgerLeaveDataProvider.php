<?php

namespace App\Domain\Leave\Integration;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The real Attendance leave provider (replaces NullLeaveDataProvider). Reads ONLY active segments of APPROVED requests, so a
 * cancelled, rejected, withdrawn or still-pending request never changes attendance. Returns minutes, portion, an opaque
 * reference (the request uuid) and the paid flag; never reasons, documents or the leave type (medical privacy).
 *
 * Normalized per day: a full day returns portion `full_day` with 1440 minutes (the whole logical day, so a shift of any length is
 * fully covered); half days return first_half / second_half with their minutes; quarter / hourly leave returns `partial` with the
 * minutes taken. Two halves on one day normalize to a full day.
 */
final class LedgerLeaveDataProvider implements LeaveDataProvider
{
    private const APPROVED = ['approved', 'partially_cancelled'];

    public function coverage(int $employeeId, string $date): ?array
    {
        return $this->coverageForRange([$employeeId], $date, $date)[$employeeId.'|'.$date] ?? null;
    }

    public function coverageForRange(array $employeeIds, string $from, string $to): array
    {
        $out = [];
        $tenant = app(TenantContext::class)->id;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $employeeIds))), 500) as $chunk) {
            $rows = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->where('s.tenant_id', $tenant)->whereIn('s.employee_id', $chunk)
                ->whereBetween('s.leave_date', [$from, $to])->where('s.status', 'active')->where('s.charged', true)->whereIn('r.status', self::APPROVED)
                ->orderBy('s.leave_date')->orderBy('s.id')->get(['s.employee_id', 's.leave_date', 's.portion', 's.minutes', 's.pay_class', 'r.uuid']);
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row->employee_id.'|'.$row->leave_date][] = $row;
            }
            foreach ($grouped as $key => $segments) {
                $out[$key] = $this->normalize($segments);
            }
        }

        return $out;
    }

    /** @param array<int,object> $segments */
    private function normalize(array $segments): array
    {
        $portions = array_column($segments, 'portion');
        $minutes = array_sum(array_column($segments, 'minutes'));
        $paid = ! array_filter($segments, fn ($s) => in_array($s->pay_class, ['unpaid', 'lop'], true));
        $full = in_array('full_day', $portions, true) || (in_array('first_half', $portions, true) && in_array('second_half', $portions, true));
        if ($full) {
            return ['minutes' => 1440, 'portion' => 'full_day', 'reference' => $segments[0]->uuid, 'paid' => $paid];
        }
        $only = array_unique($portions);
        $portion = count($only) === 1 && in_array($only[0], ['first_half', 'second_half'], true) ? $only[0] : 'partial';

        return ['minutes' => (int) $minutes, 'portion' => $portion, 'reference' => $segments[0]->uuid, 'paid' => $paid];
    }
}
