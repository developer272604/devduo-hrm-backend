<?php

namespace Tests\Feature\Integrations;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Integrations\Api\ApiClientService;
use App\Domain\Integrations\Webhooks\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

abstract class IntegrationsTestCase extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected const API = '/api/v1/integrations/';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->bootTenant();
        foreach (['local', 'attendance_private', 'hrm_imports'] as $disk) {
            Storage::fake($disk);
            config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => Storage::disk($disk)->path(''), 'throw' => true]]);
        }
        // Attendance recalculation is another module's concern; keep it out of these tests.
        $this->app->instance(AttendanceRecalculator::class, new class implements AttendanceRecalculator
        {
            public function recalculateDay(int $e, string $d, string $t, ?int $by = null): ?int
            {
                return 1;
            }

            public function recalculate(array $s, string $t, ?int $by = null, ?string $k = null): string
            {
                return 'run';
            }
        });
        // Hostnames resolve to a fixed public address; no real DNS in tests.
        UrlGuard::resolveUsing(fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34']);
        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        UrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    /** @return array{0:string,1:string} [uuid, plaintext key] */
    protected function makeClient(array $extra = []): array
    {
        $this->signIn($this->admin);
        $res = $this->postJson(self::API.'api-clients', $extra + ['name' => 'HR data lake', 'scopes' => ['employees:read', 'departments:read']])->assertCreated();

        $this->context($this->admin->tenant_id);

        return [$res->json('client.uuid'), $res->json('key')];
    }

    /** Public API call with an explicit key (headers must not leak between calls). */
    protected function pub(?string $key, string $path, array $server = [])
    {
        return $this->withServerVariables($server)->getJson($path, $key ? $this->bearer($key) : []);
    }

    protected function bearer(string $key): array
    {
        return ['Authorization' => 'Bearer '.$key];
    }

    protected function service(): ApiClientService
    {
        return app(ApiClientService::class);
    }
}
