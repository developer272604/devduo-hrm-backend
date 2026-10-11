<?php

namespace Tests\Feature\Integrations;

use App\Domain\Events\DomainEvents;
use App\Domain\Integrations\Models\WebhookEndpoint;
use App\Domain\Integrations\RunDue;
use App\Domain\Integrations\Webhooks\UrlGuard;
use App\Domain\Integrations\Webhooks\WebhookService;
use App\Domain\Integrations\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WebhookTest extends IntegrationsTestCase
{
    private function deliver(): array
    {
        $this->context($this->admin->tenant_id);

        return app(WebhookService::class)->deliverDue();
    }

    private function enqueue(): int
    {
        $this->context($this->admin->tenant_id);

        return app(WebhookService::class)->enqueueEvents();
    }

    private function endpoint(array $extra = []): array
    {
        $this->signIn($this->admin);
        $res = $this->postJson(self::API.'webhooks', $extra + ['name' => 'ERP', 'url' => 'https://hooks.example.com/devduo', 'events' => ['EmployeeCreated', 'EmployeeSeparated']])->assertCreated();
        $this->context($this->admin->tenant_id);

        return [$res->json('endpoint.uuid'), $res->json('secret')];
    }

    public function test_signature_verifies_and_replay_window_and_tampering_are_rejected(): void
    {
        $body = '{"id":"e1"}';
        $sig = WebhookSigner::sign('whsec_x', $body, 1_800_000_000);
        $this->assertTrue(WebhookSigner::verify('whsec_x', $body, $sig, 300, 1_800_000_100));
        $this->assertFalse(WebhookSigner::verify('whsec_x', $body, $sig, 300, 1_800_000_301), 'older than the replay window');
        $this->assertFalse(WebhookSigner::verify('whsec_x', $body, $sig, 300, 1_799_999_000), 'timestamp from the future');
        $this->assertFalse(WebhookSigner::verify('whsec_x', '{"id":"e2"}', $sig, 300, 1_800_000_100), 'tampered body');
        $this->assertFalse(WebhookSigner::verify('whsec_other', $body, $sig, 300, 1_800_000_100), 'wrong secret');
        $this->assertFalse(WebhookSigner::verify('whsec_x', $body, 'v1=abc', 300, 1_800_000_100));
        // A signature re-stamped with a fresh timestamp but the old MAC must fail.
        $forged = 't=1800000200,v1='.explode('v1=', $sig)[1];
        $this->assertFalse(WebhookSigner::verify('whsec_x', $body, $forged, 300, 1_800_000_200));
    }

    public function test_ssrf_blocklist(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '224.0.0.1', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::'] as $ip) {
            $this->assertTrue(UrlGuard::isBlocked($ip), "$ip must be blocked");
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            $this->assertFalse(UrlGuard::isBlocked($ip), "$ip must be allowed");
        }
        $this->signIn($this->admin);
        foreach (['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://[::1]/x', 'https://svc.internal.corp/x', 'https://user:pw@hooks.example.com/x', 'https://hooks.example.com:8080/x', 'ftp://hooks.example.com/x', 'not a url'] as $url) {
            $this->postJson(self::API.'webhooks', ['name' => 'x', 'url' => $url, 'events' => ['EmployeeCreated']])->assertStatus(422)->assertJsonValidationErrors('url');
        }
        // Explicit dev opt-in allows plain http only in local/testing; https to a private host stays blocked.
        $this->postJson(self::API.'webhooks', ['name' => 'dev', 'url' => 'http://127.0.0.1:9000/hook', 'events' => ['EmployeeCreated'], 'allow_insecure' => true])->assertCreated();
        $this->postJson(self::API.'webhooks', ['name' => 'dev', 'url' => 'https://10.0.0.1/hook', 'events' => ['EmployeeCreated'], 'allow_insecure' => true])->assertStatus(422);
        $this->postJson(self::API.'webhooks', ['name' => 'x', 'url' => 'https://hooks.example.com/x', 'events' => ['Salary.Changed']])->assertStatus(422);
    }

    public function test_ssrf_is_rechecked_at_delivery_time(): void
    {
        Http::fake();
        [$uuid] = $this->endpoint();
        // DNS later points at an internal address (rebinding): delivery must refuse and never call out.
        UrlGuard::resolveUsing(fn () => ['10.0.0.9']);
        $this->postJson(self::API."webhooks/$uuid/test")->assertStatus(202);
        $this->deliver();
        Http::assertNothingSent();
        $row = DB::table('integration_webhook_deliveries')->first();
        $this->assertSame('retry', $row->status);
        $this->assertStringContainsString('private', $row->last_error);
    }

    public function test_secret_is_shown_once_encrypted_and_deliveries_are_signed(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        [$uuid, $secret] = $this->endpoint();
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertNotSame($secret, DB::table('integration_webhook_endpoints')->value('secret'));
        $this->assertStringNotContainsString($secret, $this->getJson(self::API.'webhooks')->assertOk()->getContent());

        $this->postJson(self::API."webhooks/$uuid/test")->assertStatus(202);
        $this->deliver();
        Http::assertSent(function (Request $r) use ($secret) {
            return $r->hasHeader('X-Devduo-Event', 'webhook.test') && $r->hasHeader('Idempotency-Key')
                && WebhookSigner::verify($secret, $r->body(), $r->header('X-Devduo-Signature')[0]);
        });
        $this->assertSame('delivered', DB::table('integration_webhook_deliveries')->value('status'));

        $new = $this->postJson(self::API."webhooks/$uuid/rotate-secret")->assertOk()->json('secret');
        $this->context($this->admin->tenant_id);
        $this->assertNotSame($secret, $new);
        $this->assertSame($new, WebhookEndpoint::byUuid($uuid)->secret);
    }

    public function test_events_are_minimized_idempotent_and_replayable(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        [$uuid] = $this->endpoint();
        $employee = $this->hire('Secret Name', ['personal_email' => 'private@example.com']);
        $this->context($this->admin->tenant_id);
        app(DomainEvents::class)->record('EmployeeSeparated', ['employee' => $employee, 'summary' => 'Secret Name resigned citing health reasons', 'payload' => ['to' => 'separated', 'reason' => 'medical leave details']]);
        app(DomainEvents::class)->record('TagAssigned', ['employee' => $employee, 'summary' => 'not subscribed']);

        $this->assertSame(2, $this->enqueue()); // EmployeeCreated (hire after endpoint) + EmployeeSeparated; TagAssigned not subscribed
        $this->assertSame(0, $this->enqueue(), 'second scan must not duplicate');
        $this->deliver();
        $this->deliver();
        $this->assertSame(2, DB::table('integration_webhook_deliveries')->count());
        Http::assertSentCount(2);
        $payloads = DB::table('integration_webhook_deliveries')->pluck('payload')->implode(' ');
        foreach (['Secret Name', 'private@example.com', 'health', 'medical', 'summary', 'resigned'] as $needle) {
            $this->assertStringNotContainsString($needle, $payloads);
        }
        $this->assertStringContainsString($employee->uuid, $payloads);

        $delivery = DB::table('integration_webhook_deliveries')->where('event_type', 'EmployeeSeparated')->first();
        $this->postJson(self::API."webhook-deliveries/{$delivery->uuid}/replay")->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('replays', 1);
        $this->deliver();
        Http::assertSentCount(3);
        $this->assertSame($delivery->idempotency_key, DB::table('integration_webhook_deliveries')->where('uuid', $delivery->uuid)->value('idempotency_key'));
        $log = $this->getJson(self::API."webhooks/$uuid/deliveries")->assertOk()->json('data');
        $this->assertCount(2, $log);
    }

    public function test_retries_back_off_then_dead_letter_and_manual_replay_recovers(): void
    {
        $status = 500;
        Http::fake(function () use (&$status) { return Http::response('x', $status); });
        [$uuid] = $this->endpoint();
        $this->postJson(self::API."webhooks/$uuid/test")->assertStatus(202);
        $expected = [60, 300, 1800, 7200, 21600];
        foreach ($expected as $i => $delay) {
            $this->deliver();
            $d = DB::table('integration_webhook_deliveries')->first();
            $this->assertSame('retry', $d->status);
            $this->assertSame($i + 1, (int) $d->attempts);
            $this->assertSame(500, (int) $d->last_status_code);
            $this->assertEqualsWithDelta($delay, now()->diffInSeconds($d->next_at, false), 2);
            $this->deliver(); // not yet due: must not be re-attempted
            $this->assertSame($i + 1, (int) DB::table('integration_webhook_deliveries')->value('attempts'));
            $this->travel($delay + 1)->seconds();
        }
        $this->deliver();
        $d = DB::table('integration_webhook_deliveries')->first();
        $this->assertSame('dead_letter', $d->status);
        $this->assertSame(6, (int) $d->attempts);
        Http::assertSentCount(6);
        $this->deliver();
        Http::assertSentCount(6);

        $status = 200;
        $this->signIn($this->admin);
        $this->postJson(self::API."webhook-deliveries/{$d->uuid}/replay")->assertOk();
        $this->deliver();
        $this->assertSame('delivered', DB::table('integration_webhook_deliveries')->value('status'));
        $this->assertSame(0, (int) DB::table('integration_webhook_endpoints')->value('consecutive_failures'));
    }

    public function test_run_due_command_is_idempotent_and_tenant_isolated(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        [$uuid] = $this->endpoint();
        $alpha = $this->admin->tenant_id;
        $this->hire('New Joiner');
        $this->context($alpha);
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->context($alpha);
        $this->assertSame(1, DB::table('integration_webhook_deliveries')->where('event_type', 'EmployeeCreated')->count());
        Http::assertSentCount(1);

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->postJson(self::API."webhooks/$uuid/test")->assertNotFound();
        $this->postJson(self::API."webhooks/$uuid/rotate-secret")->assertNotFound();
        $this->getJson(self::API."webhooks/$uuid/deliveries")->assertNotFound();
        $this->context($this->admin->tenant_id);
        $this->hire('Beta Joiner');
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->context($alpha);
        $this->assertSame(1, DB::table('integration_webhook_deliveries')->where('event_type', 'EmployeeCreated')->count(), 'beta events never reach alpha endpoints');
        $this->signIn($this->userWithRole('Team member', 'tm@alpha.test'));
        $this->postJson(self::API.'webhooks', ['name' => 'x', 'url' => 'https://hooks.example.com/x', 'events' => ['EmployeeCreated']])->assertForbidden();
        $this->assertTrue(RunDue::class !== '');
    }
}
