<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Models\Delivery;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\Schedule;
use App\Domain\Audit\Audit;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Scheduled report delivery. Each recipient gets a file computed in THEIR OWN scope at send time, only if they still hold the
 * analytics and source permissions. Idempotent: one delivery row per schedule, recipient, due slot and channel.
 */
final class ReportDelivery
{
    public const TTL_DAYS = 7;

    public function __construct(private AnalyticsService $analytics, private NotificationService $notifications) {}

    public static function nextRun(string $frequency, ?int $dayOfWeek, ?int $dayOfMonth, string $time, CarbonImmutable $after, string $tz): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $local = $after->setTimezone($tz);
        $at = fn (CarbonImmutable $day) => $day->startOfDay()->setTime($h, $m);
        if ($frequency === 'daily') {
            for ($i = 0; $i < 3; $i++) {
                $c = $at($local->addDays($i));
                if ($c > $local) {
                    return $c->utc();
                }
            }
        } elseif ($frequency === 'weekly') {
            for ($i = 0; $i < 8; $i++) {
                $c = $at($local->addDays($i));
                if ($c->dayOfWeek === (int) $dayOfWeek && $c > $local) {
                    return $c->utc();
                }
            }
        } else {
            for ($i = 0; $i < 3; $i++) {
                $month = $local->startOfMonth()->addMonthsNoOverflow($i);
                $c = $at($month->setDay(min((int) $dayOfMonth, $month->daysInMonth)));
                if ($c > $local) {
                    return $c->utc();
                }
            }
        }

        return $at($local->addDay())->utc();
    }

    public function scheduleNext(Schedule $s, ?CarbonImmutable $after = null): CarbonImmutable
    {
        return self::nextRun($s->frequency, $s->day_of_week, $s->day_of_month, $s->time_of_day, $after ?? CarbonImmutable::now('UTC'), TenantClock::timezone());
    }

    /** Why this user cannot receive this report right now, or null when allowed. */
    public function ineligible(User $recipient, array $spec): ?string
    {
        $metric = AnalyticsService::CATALOG[$spec['metric']] ?? null;
        if (! $metric || ! $recipient->active || $recipient->tenant_id !== app(TenantContext::class)->id) {
            return 'Recipient is not an active member of this workspace.';
        }
        if (! $recipient->allows('analytics.view') || ! $recipient->allows($metric['permission']) || ! in_array($metric['module'], $recipient->tenant->settings['modules'] ?? [], true)) {
            return 'Recipient no longer holds the permission required for this report.';
        }

        return null;
    }

    /** @return array{processed:int,sent:int,skipped:int} */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $stats = ['processed' => 0, 'sent' => 0, 'skipped' => 0];
        $due = Schedule::query()->where('active', true)->where('next_run_at', '<=', $now)->orderBy('next_run_at')->get();
        foreach ($due as $schedule) {
            $fresh = Schedule::query()->where('id', $schedule->id)->where('active', true)->where('next_run_at', '<=', $now)->first();
            if (! $fresh) {
                continue;
            }
            $stats['processed']++;
            $slot = CarbonImmutable::parse((string) $fresh->getRawOriginal('next_run_at'), 'UTC')->format('Y-m-d\TH:i');
            foreach ($this->process($fresh, $slot) as $status) {
                $status === 'sent' ? $stats['sent']++ : $stats['skipped']++;
            }
            $fresh->update(['last_run_at' => $now, 'next_run_at' => $this->scheduleNext($fresh, $now)]);
        }

        return $stats;
    }

    /** @return array<int,string> statuses recorded for this slot */
    private function process(Schedule $s, string $slot): array
    {
        $report = Report::query()->where('id', $s->report_id)->first();
        $owner = User::query()->where('tenant_id', $s->tenant_id)->find($s->owner_id);
        $statuses = [];
        foreach ((array) $s->recipient_ids as $recipientId) {
            $delivery = Delivery::query()->firstOrCreate(['schedule_id' => $s->id, 'recipient_id' => $recipientId, 'slot_key' => $slot, 'channel' => 'in_app'], ['status' => 'pending', 'attempts' => 0]);
            if (in_array($delivery->status, ['sent', 'skipped_permission', 'skipped_blocked'], true) || $delivery->attempts >= 3) {
                $statuses[] = $delivery->status;

                continue;
            }
            $delivery->forceFill(['attempts' => $delivery->attempts + 1, 'last_attempt_at' => now()])->save();
            $statuses[] = $this->deliver($delivery, $s, $report, $owner, (int) $recipientId);
            if (in_array('email', (array) $s->channels, true)) {
                $this->email($s, $slot, (int) $recipientId, $delivery->fresh());
            }
        }

        return $statuses;
    }

    private function deliver(Delivery $delivery, Schedule $s, ?Report $report, ?User $owner, int $recipientId): string
    {
        $recipient = User::query()->where('tenant_id', $s->tenant_id)->find($recipientId);
        if (! $report) {
            $reason = 'The saved report no longer exists.';
        } elseif (! $owner || ! $owner->active || ! $owner->allows('analytics.export') || ! $owner->allows('analytics.dashboards.manage')) {
            $reason = 'The schedule owner no longer holds export and scheduling permission.';
        } elseif (! $recipient) {
            $reason = 'Recipient is not an active member of this workspace.';
        } else {
            $reason = $this->ineligible($recipient, $report->spec);
        }
        if ($reason) {
            return $this->finish($delivery, 'skipped_permission', $reason);
        }
        try {
            $result = $this->analytics->query($recipient, $report->spec, true, 'scheduled');
        } catch (HttpException) {
            return $this->finish($delivery, 'skipped_permission', 'Recipient lost access to the data scope or source for this report.');
        } catch (ValidationException $e) {
            return $this->finish($delivery, 'skipped_blocked', collect($e->errors())->flatten()->first() ?? 'The report is currently not computable.');
        } catch (\Throwable) {
            return $this->finish($delivery, 'failed', 'Report generation failed; it will be retried.');
        }
        $csv = ReportCsv::render($result, false, ReportCsv::sensitive($report->spec));
        $path = 'analytics/deliveries/'.$s->tenant_id.'/'.$delivery->uuid.'.csv';
        Storage::disk('local')->put($path, $csv['csv']);
        $expires = now()->addDays(self::TTL_DAYS);
        $delivery->forceFill(['path' => $path, 'expires_at' => $expires])->save();
        $this->notifications->send($recipient->id, 'Scheduled report: '.$report->name, 'Your scheduled report is ready. The download link is available until '.$expires->toDateString().'.', 'analytics.report', '/analytics/schedules?delivery='.$delivery->uuid, true);
        Audit::record('analytics.report.delivered', 'analytics', 'delivery:'.$delivery->uuid, ['recipient_id' => $recipient->id]);

        return $this->finish($delivery, 'sent', null);
    }

    private function email(Schedule $s, string $slot, int $recipientId, Delivery $inApp): void
    {
        $row = Delivery::query()->firstOrCreate(['schedule_id' => $s->id, 'recipient_id' => $recipientId, 'slot_key' => $slot, 'channel' => 'email'], ['status' => 'pending', 'attempts' => 0]);
        if (in_array($row->status, ['sent', 'unavailable'], true)) {
            return;
        }
        $adapter = app()->bound(ReportEmailAdapter::class) ? app(ReportEmailAdapter::class) : new NullReportEmailAdapter;
        $status = 'unavailable';
        $reason = 'No email provider is configured; the in-app notification was used.';
        if ($inApp->status !== 'sent') {
            $reason = 'In-app delivery was not completed, so email was not attempted.';
        } elseif ($adapter->isConfigured()) {
            $user = User::query()->find($recipientId);
            $status = $user && $adapter->send($user, 'Scheduled report ready', 'A scheduled report is waiting in the workspace.', '/analytics/schedules?delivery='.$inApp->uuid) ? 'sent' : 'failed';
            $reason = $status === 'sent' ? null : 'The email adapter reported a failure.';
        }
        $row->forceFill(['attempts' => $row->attempts + 1, 'last_attempt_at' => now(), 'status' => $status, 'reason' => $reason])->save();
    }

    private function finish(Delivery $d, string $status, ?string $reason): string
    {
        $d->forceFill(['status' => $status, 'reason' => $reason])->save();

        return $status;
    }

    /** Removes expired files; the delivery row (attempt history) is kept. */
    public function purgeExpired(): int
    {
        $n = 0;
        foreach (Delivery::query()->whereNotNull('path')->where('expires_at', '<', now())->get() as $d) {
            Storage::disk('local')->delete($d->path);
            $d->forceFill(['path' => null])->save();
            $n++;
        }

        return $n;
    }
}
