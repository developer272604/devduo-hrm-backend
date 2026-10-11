<?php

namespace App\Domain\Integrations\Health;

use App\Domain\Integrations\IntegrationService;
use App\Domain\Integrations\Models\ApiClient;
use App\Domain\Integrations\Models\ApiRequest;
use App\Domain\Integrations\Models\ImportProfile;
use App\Domain\Integrations\Models\JobRun;
use App\Domain\Integrations\Models\WebhookDelivery;
use App\Domain\Integrations\Models\WebhookEndpoint;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/** Integration Health Center. Local capabilities are reported from real data; provider adapters without code are explicitly NOT LIVE. */
final class HealthService
{
    /** Provider-gated adapters: no code, no vendor sandbox, no credentials. Never reported as healthy. */
    public const GATED = [
        'sap' => 'SAP', 'workday' => 'Workday', 'zkteco' => 'ZKTeco biometric devices', 'suprema' => 'Suprema biometric devices', 'google_workspace' => 'Google Workspace SSO/SCIM',
        'microsoft_365' => 'Microsoft 365 SSO/SCIM', 'accounting_gl' => 'Accounting / GL', 'bank_payments' => 'Bank and payment providers', 'ats_job_boards' => 'ATS job boards',
    ];

    public static function heartbeat(): void
    {
        Cache::put('integrations:run-due:'.app(TenantContext::class)->id, now()->toIso8601String(), now()->addDays(2));
    }

    public function overview(): array
    {
        $since = now()->subDay();
        $clients = ApiClient::query()->get();
        $requests = ApiRequest::query()->where('created_at', '>=', $since);
        $endpoints = WebhookEndpoint::query()->get();
        $deliveries = WebhookDelivery::query();
        $dead = (clone $deliveries)->where('status', 'dead_letter')->count();
        $backlog = (clone $deliveries)->whereIn('status', ['pending', 'retry'])->count();
        $oldest = (clone $deliveries)->whereIn('status', ['pending', 'retry'])->min('next_at');
        $failedRuns = JobRun::query()->where('status', 'failed')->where('created_at', '>=', $since)->count();
        $profiles = ImportProfile::query()->where('status', 'active')->count();
        $lastRun = Cache::get('integrations:run-due:'.app(TenantContext::class)->id);

        $issues = [];
        if ($dead) {
            $issues[] = ['severity' => 'high', 'area' => 'webhooks', 'message' => $dead.' webhook delivery(ies) are in the dead-letter queue. Review and replay them.'];
        }
        if ($endpoints->where('status', 'active')->contains(fn ($e) => $e->consecutive_failures >= 5)) {
            $issues[] = ['severity' => 'medium', 'area' => 'webhooks', 'message' => 'A webhook endpoint has failed 5 or more deliveries in a row.'];
        }
        if ($failedRuns) {
            $issues[] = ['severity' => 'medium', 'area' => 'imports', 'message' => $failedRuns.' import run(s) failed in the last 24 hours.'];
        }
        $throttled = (clone $requests)->where('status', 429)->count();
        if ($throttled) {
            $issues[] = ['severity' => 'low', 'area' => 'api', 'message' => $throttled.' public API request(s) were rate limited in the last 24 hours.'];
        }
        if (($endpoints->where('status', 'active')->count() || $profiles) && ! $lastRun) {
            $issues[] = ['severity' => 'medium', 'area' => 'scheduler', 'message' => 'integrations:run-due has not run for this workspace. Check the scheduler.'];
        }

        return [
            'status' => collect($issues)->contains('severity', 'high') ? 'attention' : ($issues ? 'degraded' : 'ok'),
            'issues' => $issues,
            'api' => ['active_clients' => $clients->where('status', 'active')->count(), 'revoked_clients' => $clients->where('status', 'revoked')->count(), 'requests_24h' => (clone $requests)->count(),
                'errors_24h' => (clone $requests)->where('status', '>=', 400)->count(), 'rate_limited_24h' => $throttled],
            'webhooks' => ['active_endpoints' => $endpoints->where('status', 'active')->count(), 'backlog' => $backlog, 'dead_letters' => $dead, 'oldest_pending_at' => $oldest ? (string) \Carbon\Carbon::parse($oldest)->toIso8601String() : null,
                'delivered_24h' => (clone $deliveries)->where('status', 'delivered')->where('delivered_at', '>=', $since)->count()],
            'imports' => ['active_profiles' => $profiles, 'failed_24h' => $failedRuns, 'runs_24h' => JobRun::query()->where('created_at', '>=', $since)->count()],
            'scheduler' => ['last_run_due_at' => $lastRun],
            'adapters' => $this->adapters(),
        ];
    }

    /** @return array<int,array{key:string,label:string,state:string,live:bool,note:string}> */
    public function adapters(): array
    {
        $local = [
            ['key' => 'public_api', 'label' => 'Public REST API v1 (read-only)', 'state' => 'live', 'live' => true, 'note' => 'Local runtime. Scoped, rate limited, audited.'],
            ['key' => 'outbound_webhooks', 'label' => 'Outbound signed webhooks', 'state' => 'live', 'live' => true, 'note' => 'Delivers to your https endpoints. Not verified against any specific vendor.'],
            ['key' => 'file_import_attendance', 'label' => 'File drop: attendance punches (CSV/JSON)', 'state' => 'live', 'live' => true, 'note' => 'Local file import using the Attendance import service.'],
            ['key' => 'file_import_employees', 'label' => 'File drop: employee master (CSV/JSON)', 'state' => 'live', 'live' => true, 'note' => 'Local file import using the People import service.'],
        ];
        $gated = collect(self::GATED)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'state' => 'not_live', 'live' => false,
            'note' => 'No adapter is installed. Requires vendor credentials and a sandbox; nothing is connected.'])->values()->all();

        return [...$local, ...$gated];
    }

    /** Local self-checks only; none of these touch an external network. */
    public function diagnostics(): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail');
        };
        try {
            $add('encryption', 'Secret encryption', Crypt::decryptString(Crypt::encryptString('ok')) === 'ok' ? 'pass' : 'fail', 'Webhook secrets are encrypted at rest with the application key.');
        } catch (\Throwable) {
            $add('encryption', 'Secret encryption', 'fail', 'The application key is missing or invalid.');
        }
        try {
            $probe = 'integrations/health-probe.txt';
            Storage::disk('local')->put($probe, 'ok');
            Storage::disk('local')->delete($probe);
            $add('storage', 'Private file storage', 'pass', 'Import files can be written.');
        } catch (\Throwable) {
            $add('storage', 'Private file storage', 'fail', 'The private storage disk is not writable.');
        }
        $queue = config('queue.default');
        $add('queue', 'Queue connection', $queue === 'sync' ? 'warn' : 'pass', $queue === 'sync' ? 'Queue is "sync": large employee imports run inline. Use a real queue in production.' : 'Queue driver: '.$queue);
        $last = Cache::get('integrations:run-due:'.app(TenantContext::class)->id);
        $add('scheduler', 'Scheduler (integrations:run-due)', $last && now()->diffInMinutes($last) < 180 ? 'pass' : 'warn', $last ? 'Last run '.$last : 'Has not run yet for this workspace.');
        $add('providers', 'Provider adapters', 'warn', count(self::GATED).' provider adapters and '.count(IntegrationService::PROVIDERS).' catalog providers are not live; they report not_configured and make no network calls.');

        return $checks;
    }
}
