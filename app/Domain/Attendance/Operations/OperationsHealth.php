<?php

namespace App\Domain\Attendance\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Observability read model for admins. Friendly text and counts only; never exception messages or stack traces. */
final class OperationsHealth
{
    public function report(): array
    {
        $t = Ops::tenantId();
        $since = now()->subDay();
        $runs = DB::table('attendance_processing_runs')->where('tenant_id', $t)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', $since)->count();
        $badDevices = DB::table('attendance_devices')->where('tenant_id', $t)->where('status', '!=', 'inactive')->get(['name', 'status', 'last_sync_at', 'last_sync_status', 'sync_interval_minutes'])
            ->filter(fn ($d) => $d->status === 'error' || in_array($d->last_sync_status, ['failed', 'error'], true) || ! $d->last_sync_at || CarbonImmutable::parse($d->last_sync_at, 'UTC')->lessThan(now()->subMinutes(2 * max(1, (int) $d->sync_interval_minutes))));
        $failedSyncs = DB::table('device_sync_runs')->where('tenant_id', $t)->where('status', 'failed')->where('started_at', '>=', $since)->count();
        $imports = DB::table('attendance_imports')->where('tenant_id', $t)->where('updated_at', '>=', now()->subDays(7))->where(fn ($w) => $w->where('status', 'failed')->orWhere('error_rows', '>', 0))->count();
        $queue = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', $since)->where('payload', 'like', '%Attendance%')->count() : 0;
        $slow = DB::table('attendance_processing_runs')->where('tenant_id', $t)->whereNotNull('started_at')->where('created_at', '>=', now()->subDays(2))->get(['started_at', 'finished_at', 'status'])
            ->filter(fn ($r) => ($r->finished_at ? CarbonImmutable::parse($r->finished_at) : now())->diffInMinutes(CarbonImmutable::parse($r->started_at)) > 15)->count();
        $checks = [
            'processing_failures' => ['count' => $runs, 'window' => '24h', 'message' => $runs ? $runs.' processing run(s) did not complete. Open processing to review and retry.' : 'No processing failures.'],
            'device_sync' => ['count' => $badDevices->count() + $failedSyncs, 'window' => '24h', 'message' => ($badDevices->count() + $failedSyncs) ? 'Some devices are overdue or failed to sync: '.$badDevices->pluck('name')->take(5)->implode(', ').'.' : 'All devices are syncing.'],
            'import_failures' => ['count' => $imports, 'window' => '7d', 'message' => $imports ? $imports.' import(s) failed or had rejected rows.' : 'No import problems.'],
            'queue_failures' => ['count' => $queue, 'window' => '24h', 'message' => $queue ? $queue.' background attendance job(s) failed. Ask your administrator to retry them.' : 'No failed background jobs.'],
            'abnormal_durations' => ['count' => $slow, 'window' => '48h', 'message' => $slow ? $slow.' processing run(s) took longer than 15 minutes.' : 'Processing durations are normal.'],
        ];
        $bad = array_sum(array_column($checks, 'count'));
        if ($bad) {
            Log::info('Attendance health has open issues', ['tenant' => $t, 'issues' => array_map(fn ($c) => $c['count'], $checks)]);
        }

        return ['status' => $bad ? 'attention_needed' : 'healthy', 'checked_at' => Ops::iso(now()), 'checks' => $checks];
    }
}
