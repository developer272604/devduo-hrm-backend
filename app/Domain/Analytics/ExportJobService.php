<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Models\ExportJob;
use App\Domain\Audit\Audit;
use App\Domain\Security\LegalHoldService;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Asynchronous exports: row-limited, masked, audited, permission re-checked when the job runs and when it is downloaded. */
final class ExportJobService
{
    public const DEFAULT_ROW_LIMIT = 5000;

    public const TTL_HOURS = 48;

    public function __construct(private AnalyticsService $analytics) {}

    public function request(User $u, array $input): ExportJob
    {
        abort_unless($u->allows('analytics.export'), 403);
        $spec = $this->analytics->validateSpec($input);
        $this->analytics->query($u, $spec, false); // fail fast on permission / scope / quality
        $limit = max(1, min(50000, (int) config('analytics.export_row_limit', self::DEFAULT_ROW_LIMIT)));
        $job = ExportJob::create(['requested_by' => $u->id, 'spec' => $spec + ['with_counts' => (bool) ($input['with_counts'] ?? false)], 'status' => 'queued', 'row_limit' => $limit]);
        Audit::record('analytics.export.requested', 'analytics', 'export:'.$job->uuid, ['metric' => $spec['metric']]);
        RunAnalyticsExport::dispatch($job->tenant_id, $job->uuid);

        return $job->fresh();
    }

    public function process(string $uuid): void
    {
        $job = ExportJob::query()->where('uuid', $uuid)->first();
        if (! $job || $job->status !== 'queued') {
            return; // idempotent: a retry never re-runs a finished job
        }
        $job->update(['status' => 'running']);
        $user = User::query()->where('tenant_id', $job->tenant_id)->find($job->requested_by);
        try {
            if (! $user || ! $user->active || ! $user->allows('analytics.export')) {
                throw new \RuntimeException('The requester no longer holds export permission.');
            }
            $spec = $job->spec;
            $withCounts = (bool) ($spec['with_counts'] ?? false);
            unset($spec['with_counts']);
            $result = $this->analytics->query($user, $spec, true, 'export_job');
            if (count($result['rows']) > $job->row_limit) {
                throw new \RuntimeException('Row limit of '.$job->row_limit.' exceeded; narrow the report.');
            }
            $csv = ReportCsv::render($result, $withCounts, ReportCsv::sensitive($spec));
            $path = 'analytics/exports/'.$job->tenant_id.'/'.$job->uuid.'.csv';
            Storage::disk('local')->put($path, $csv['csv']);
            $job->update(['status' => 'completed', 'path' => $path, 'row_count' => $csv['rows'], 'masked_columns' => $csv['masked'], 'completed_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS)]);
            Audit::record('analytics.export.completed', 'analytics', 'export:'.$job->uuid, ['requested_by' => $job->requested_by, 'rows' => $csv['rows'], 'masked' => $csv['masked']]);
        } catch (\Throwable $e) {
            $message = $e instanceof ValidationException ? (collect($e->errors())->flatten()->first() ?? 'Report is blocked.') : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? 'Permission or data scope no longer allows this export.' : $e->getMessage());
            $job->update(['status' => 'failed', 'error' => mb_substr((string) $message, 0, 250), 'completed_at' => now()]);
            Audit::record('analytics.export.failed', 'analytics', 'export:'.$job->uuid, ['requested_by' => $job->requested_by]);
        }
    }

    public function purgeExpired(): int
    {
        if (LegalHoldService::held('analytics')) {
            return 0;
        }
        $n = 0;
        foreach (ExportJob::query()->where('status', 'completed')->where('expires_at', '<', now())->get() as $job) {
            if ($job->path) {
                Storage::disk('local')->delete($job->path);
            }
            $job->update(['status' => 'expired', 'path' => null]);
            $n++;
        }

        return $n;
    }
}
