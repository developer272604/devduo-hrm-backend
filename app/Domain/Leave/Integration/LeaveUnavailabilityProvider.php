<?php

namespace App\Domain\Leave\Integration;

use App\Domain\Attendance\Scheduling\UnavailabilityProvider;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Tells roster planning which employee-days are covered by approved full-day leave (the reason never names the leave type). */
final class LeaveUnavailabilityProvider implements UnavailabilityProvider
{
    public function unavailable(array $employeeIds, string $from, string $to): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $employeeIds))), 500) as $chunk) {
            foreach (DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->where('s.tenant_id', app(TenantContext::class)->id)->whereIn('s.employee_id', $chunk)
                ->whereBetween('s.leave_date', [$from, $to])->where('s.status', 'active')->where('s.charged', true)->where('s.portion', 'full_day')->whereIn('r.status', ['approved', 'partially_cancelled'])
                ->get(['s.employee_id', 's.leave_date']) as $row) {
                $out[$row->employee_id.'|'.$row->leave_date] = 'Approved leave';
            }
        }

        return $out;
    }
}
