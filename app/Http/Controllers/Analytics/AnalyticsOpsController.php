<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\DashboardService;
use App\Domain\Analytics\ExportJobService;
use App\Domain\Analytics\MetricDocs;
use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Delivery;
use App\Domain\Analytics\Models\ExportJob;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\Schedule;
use App\Domain\Analytics\ReportDelivery;
use App\Domain\Audit\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/** Explainability, role dashboards, scheduled delivery and async exports. Every action re-checks permission and scope. */
final class AnalyticsOpsController
{
    public function __construct(private AnalyticsService $analytics, private DashboardService $boards, private ReportDelivery $delivery, private ExportJobService $exports) {}

    // ---- Explainability -------------------------------------------------------------------------------------------
    public function explain(Request $r, string $key)
    {
        return $this->analytics->explain($r->user(), $key);
    }

    // ---- Dashboards -----------------------------------------------------------------------------------------------
    public function dashboards(Request $r)
    {
        return ['data' => $this->boards->visible($r->user())->map(fn ($b) => $this->boardRow($b))->all(), 'tier' => $this->boards->tier($r->user())];
    }

    public function dashboard(Request $r, string $uuid)
    {
        return $this->boards->render($r->user(), Dashboard::byUuid($uuid), $r->query('as_of'));
    }

    public function saveDashboard(Request $r, ?string $uuid = null)
    {
        $data = $this->boards->validate($r->user(), $r->all());
        $board = $uuid ? Dashboard::query()->where('uuid', $uuid)->where('owner_id', $r->user()->id)->firstOrFail() : null;
        $board ? $board->update($data) : $board = Dashboard::create($data + ['owner_id' => $r->user()->id]);
        Audit::record('analytics.dashboard.saved', 'analytics', 'dashboard:'.$board->uuid);

        return response()->json($this->boardRow($board), $uuid ? 200 : 201);
    }

    public function deleteDashboard(Request $r, string $uuid)
    {
        Dashboard::query()->where('uuid', $uuid)->where('owner_id', $r->user()->id)->firstOrFail()->delete();
        Audit::record('analytics.dashboard.deleted', 'analytics', 'dashboard:'.$uuid);

        return response()->noContent();
    }

    private function boardRow(Dashboard $b): array
    {
        return ['uuid' => $b->uuid, 'name' => $b->name, 'audience' => $b->audience, 'widgets' => $b->widgets, 'owner_id' => $b->owner_id];
    }

    // ---- Schedules ------------------------------------------------------------------------------------------------
    public function schedules(Request $r)
    {
        $rows = Schedule::query()->where('owner_id', $r->user()->id)->latest('id')->get();
        $reports = Report::query()->whereIn('id', $rows->pluck('report_id'))->get()->keyBy('id');
        $names = User::query()->where('tenant_id', $r->user()->tenant_id)->pluck('name', 'id');

        return ['data' => $rows->map(fn ($s) => $this->scheduleRow($s, $reports->get($s->report_id), $names, true))->all()];
    }

    public function saveSchedule(Request $r, ?string $uuid = null)
    {
        $u = $r->user();
        abort_unless($u->allows('analytics.export'), 403);
        $d = $r->validate(['report_uuid' => 'required|uuid', 'frequency' => 'required|in:daily,weekly,monthly', 'day_of_week' => 'required_if:frequency,weekly|nullable|integer|between:0,6',
            'day_of_month' => 'required_if:frequency,monthly|nullable|integer|between:1,31', 'time_of_day' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recipient_ids' => 'nullable|array|max:50', 'recipient_ids.*' => 'integer', 'channels' => 'nullable|array', 'channels.*' => 'in:in_app,email', 'active' => 'nullable|boolean']);
        $report = Report::query()->where('uuid', $d['report_uuid'])->where('owner_id', $u->id)->firstOrFail();
        $ids = collect($d['recipient_ids'] ?? [$u->id])->unique()->values();
        $users = User::query()->where('tenant_id', $u->tenant_id)->whereIn('id', $ids)->get();
        if ($users->count() !== $ids->count()) {
            throw ValidationException::withMessages(['recipient_ids' => 'Every recipient must be a member of this workspace.']);
        }
        foreach ($users as $recipient) {
            if ($reason = $this->delivery->ineligible($recipient, $report->spec)) {
                throw ValidationException::withMessages(['recipient_ids' => $recipient->name.': '.$reason]);
            }
        }
        $this->analytics->query($u, $report->spec, false); // the owner must be able to run it
        $schedule = $uuid ? Schedule::query()->where('uuid', $uuid)->where('owner_id', $u->id)->firstOrFail() : new Schedule(['owner_id' => $u->id]);
        $schedule->fill(['report_id' => $report->id, 'frequency' => $d['frequency'], 'day_of_week' => $d['frequency'] === 'weekly' ? $d['day_of_week'] : null,
            'day_of_month' => $d['frequency'] === 'monthly' ? $d['day_of_month'] : null, 'time_of_day' => $d['time_of_day'], 'recipient_ids' => $ids->all(),
            'channels' => array_values(array_unique($d['channels'] ?? ['in_app'])), 'active' => $d['active'] ?? true]);
        $schedule->next_run_at = $this->delivery->scheduleNext($schedule);
        $schedule->save();
        Audit::record('analytics.schedule.saved', 'analytics', 'schedule:'.$schedule->uuid, ['recipients' => $ids->count()]);

        return response()->json($this->scheduleRow($schedule, $report, $users->pluck('name', 'id')), $uuid ? 200 : 201);
    }

    public function deleteSchedule(Request $r, string $uuid)
    {
        Schedule::query()->where('uuid', $uuid)->where('owner_id', $r->user()->id)->firstOrFail()->delete();
        Audit::record('analytics.schedule.deleted', 'analytics', 'schedule:'.$uuid);

        return response()->noContent();
    }

    private function scheduleRow(Schedule $s, ?Report $report, $names, bool $withDeliveries = false): array
    {
        $row = ['uuid' => $s->uuid, 'report' => $report ? ['uuid' => $report->uuid, 'name' => $report->name] : null, 'frequency' => $s->frequency, 'day_of_week' => $s->day_of_week, 'day_of_month' => $s->day_of_month,
            'time_of_day' => $s->time_of_day, 'timezone' => \App\Domain\Tenancy\TenantClock::timezone(), 'channels' => $s->channels, 'active' => $s->active, 'next_run_at' => $s->next_run_at?->toIso8601String(), 'last_run_at' => $s->last_run_at?->toIso8601String(),
            'recipients' => collect($s->recipient_ids)->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Unknown'])->all()];
        if ($withDeliveries) {
            $row['deliveries'] = Delivery::query()->where('schedule_id', $s->id)->latest('id')->limit(20)->get()
                ->map(fn ($d) => ['uuid' => $d->uuid, 'recipient' => $names[$d->recipient_id] ?? 'Unknown', 'channel' => $d->channel, 'status' => $d->status, 'reason' => $d->reason, 'attempts' => $d->attempts, 'slot' => $d->slot_key])->all();
        }

        return $row;
    }

    // ---- Deliveries (recipient side) -----------------------------------------------------------------------------
    public function deliveries(Request $r)
    {
        $rows = Delivery::query()->where('recipient_id', $r->user()->id)->where('channel', 'in_app')->where('status', 'sent')->whereNotNull('path')->where('expires_at', '>', now())->latest('id')->limit(50)->get();
        $schedules = Schedule::query()->whereIn('id', $rows->pluck('schedule_id'))->get()->keyBy('id');
        $reports = Report::query()->whereIn('id', $schedules->pluck('report_id'))->get()->keyBy('id');

        return ['data' => $rows->map(fn ($d) => ['uuid' => $d->uuid, 'report' => $reports->get($schedules->get($d->schedule_id)?->report_id)?->name ?? 'Report', 'delivered_at' => $d->updated_at->toIso8601String(), 'expires_at' => $d->expires_at->toIso8601String()])->all()];
    }

    public function deliveryLink(Request $r, string $uuid)
    {
        $d = $this->ownDelivery($r, $uuid);
        $expires = now()->addMinutes(10);
        Audit::record('analytics.delivery.link_issued', 'analytics', 'delivery:'.$d->uuid);

        return ['url' => URL::temporarySignedRoute('analytics.delivery.download', $expires, ['uuid' => $d->uuid], absolute: false), 'expires_at' => $expires->toIso8601String()];
    }

    public function deliveryDownload(Request $r, string $uuid)
    {
        $d = $this->ownDelivery($r, $uuid);
        $schedule = Schedule::query()->findOrFail($d->schedule_id);
        $report = Report::query()->findOrFail($schedule->report_id);
        abort_if($this->delivery->ineligible($r->user(), $report->spec) !== null, 403, 'You no longer hold the permission for this report.');
        Audit::record('analytics.delivery.downloaded', 'analytics', 'delivery:'.$d->uuid);

        return Storage::disk('local')->download($d->path, 'scheduled-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function ownDelivery(Request $r, string $uuid): Delivery
    {
        $d = Delivery::query()->where('uuid', $uuid)->where('recipient_id', $r->user()->id)->where('status', 'sent')->firstOrFail();
        abort_if(! $d->path || $d->expires_at->isPast() || ! Storage::disk('local')->exists($d->path), 410, 'This download has expired.');

        return $d;
    }

    // ---- Async exports --------------------------------------------------------------------------------------------
    public function exportJobs(Request $r)
    {
        return ['data' => ExportJob::query()->where('requested_by', $r->user()->id)->latest('id')->limit(50)->get()->map(fn ($j) => $this->jobRow($j))->all()];
    }

    public function requestExport(Request $r)
    {
        return response()->json($this->jobRow($this->exports->request($r->user(), $r->all())), 202);
    }

    public function exportJob(Request $r, string $uuid)
    {
        return $this->jobRow(ExportJob::query()->where('uuid', $uuid)->where('requested_by', $r->user()->id)->firstOrFail());
    }

    public function downloadExport(Request $r, string $uuid)
    {
        $j = ExportJob::query()->where('uuid', $uuid)->where('requested_by', $r->user()->id)->firstOrFail();
        abort_if($j->status !== 'completed' || ! $j->path || $j->expires_at->isPast(), 410, 'This export is not available.');
        $metric = AnalyticsService::CATALOG[$j->spec['metric']];
        abort_unless($r->user()->allows('analytics.export') && $r->user()->allows($metric['permission']), 403);
        Audit::record('analytics.export.downloaded', 'analytics', 'export:'.$j->uuid);

        return Storage::disk('local')->download($j->path, 'workforce-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function jobRow(ExportJob $j): array
    {
        return ['uuid' => $j->uuid, 'metric' => $j->spec['metric'], 'period' => ['from' => $j->spec['from'], 'to' => $j->spec['to']], 'status' => $j->status, 'row_limit' => $j->row_limit, 'row_count' => $j->row_count,
            'masked_columns' => $j->masked_columns ?? [], 'error' => $j->error, 'expires_at' => $j->expires_at?->toIso8601String(), 'requested_at' => $j->created_at->toIso8601String()];
    }
}
