<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Capture\Devices\DeviceProviders;
use App\Domain\Attendance\Capture\Devices\DeviceSyncService;
use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Models\DeviceSyncRun;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected Employee $asha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $t = \App\Models\Tenant::find($this->admin->tenant_id);
        $t->settings = $t->settings + ['attendance' => ['methods' => ['web', 'biometric', 'api', 'import', 'admin']]];
        $t->save();
        $this->asha = $this->hire('Asha Rao');
    }

    private function makeDevice(array $extra = []): array
    {
        $this->signIn($this->admin);
        $res = $this->postJson('/api/v1/attendance/devices', $extra + ['name' => 'Gate 1', 'type' => 'biometric', 'device_identifier' => 'SN-1', 'timezone' => 'Asia/Kolkata', 'provider_key' => 'generic_api'])->assertCreated();
        $uuid = $res->json('data.id');
        $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $this->asha->uuid, 'device_employee_id' => '1001', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->context(null);

        return [$uuid, $res->json('token')];
    }

    private function dev(string $token, string $path, array $body)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/device/v1/'.$path, $body);
    }

    public function test_token_is_shown_once_hashed_and_rotation_revokes_old(): void
    {
        [$uuid, $token] = $this->makeDevice();
        $this->assertStringStartsWith('atd_', $token);
        $this->context($this->admin->tenant_id);
        $d = AttendanceDevice::byUuid($uuid);
        $this->assertSame(hash('sha256', $token), $d->auth_token_hash);
        $this->signIn($this->admin);
        $show = $this->getJson("/api/v1/attendance/devices/$uuid")->assertOk();
        $this->assertStringNotContainsString($token, $show->getContent());
        $this->assertArrayNotHasKey('config_encrypted', $show->json('data'));
        $new = $this->postJson("/api/v1/attendance/devices/$uuid/rotate-token")->json('token');
        $this->dev($token, 'heartbeat', [])->assertStatus(401);
        $this->dev($new, 'heartbeat', ['firmware' => '1.2'])->assertOk();
        $this->signIn($this->admin);
        $this->postJson("/api/v1/attendance/devices/$uuid/revoke-token")->assertOk();
        $this->dev($new, 'heartbeat', [])->assertStatus(401);
    }

    public function test_unknown_inactive_and_cross_tenant_devices_get_identical_401(): void
    {
        [$uuid, $token] = $this->makeDevice();
        $bad = $this->dev('atd_abcdefgh.'.str_repeat('x', 40), 'heartbeat', [])->assertStatus(401)->getContent();
        $this->context($this->admin->tenant_id);
        AttendanceDevice::byUuid($uuid)->update(['status' => 'inactive']);
        $this->context(null);
        $this->assertSame($bad, $this->dev($token, 'heartbeat', [])->assertStatus(401)->getContent());
        $this->withHeader('Accept', 'application/json')->postJson('/api/device/v1/heartbeat', [])->assertStatus(401);
    }

    public function test_ingestion_batch_results_timezone_late_sync_and_single_recalculation(): void
    {
        [$uuid, $token] = $this->makeDevice();
        $calls = [];
        $this->app->instance(AttendanceRecalculator::class, new class($calls) implements AttendanceRecalculator
        {
            public function __construct(public array &$calls) {}

            public function recalculateDay(int $e, string $d, string $t, ?int $by = null): ?int
            {
                $this->calls[] = ['day', $t];

                return 1;
            }

            public function recalculate(array $s, string $t, ?int $by = null, ?string $k = null): string
            {
                $this->calls[] = ['batch', $t, $s];

                return 'run';
            }
        });
        $events = [
            ['device_employee_id' => '1001', 'timestamp' => '2026-10-07 09:00:00', 'direction' => 'IN', 'event_id' => 'E1'],
            ['device_employee_id' => '1001', 'timestamp' => '2026-10-07T09:30:00+05:30', 'event_id' => 'E2'],
            ['device_employee_id' => '1001', 'timestamp' => '2026-10-07 09:00:00', 'direction' => 'IN', 'event_id' => 'E1'],
            ['device_employee_id' => '9999', 'timestamp' => '2026-10-07 09:00:00', 'event_id' => 'E4'],
            ['device_employee_id' => '1001', 'timestamp' => 'garbage', 'event_id' => 'E5'],
            ['device_employee_id' => '1001', 'timestamp' => '2026-10-04 08:00:00', 'direction' => 'IN', 'event_id' => 'E6'],
        ];
        $res = $this->dev($token, 'events', ['events' => $events])->assertOk();
        $this->assertSame(['inserted', 'inserted', 'duplicate', 'unmapped', 'error', 'inserted'], array_column($res->json('results'), 'status'));
        $this->assertSame([['batch', 'device_sync', ['employee_ids' => [$this->asha->id], 'from' => '2026-10-04', 'to' => '2026-10-07']]], $calls);
        $this->context($this->admin->tenant_id);
        $first = AttendanceEvent::where('external_ref', 'E1')->first();
        $this->assertSame('2026-10-07 03:30:00', $first->punched_at->format('Y-m-d H:i:s')); // device zone IST -> UTC
        $this->assertSame('UNKNOWN', AttendanceEvent::where('external_ref', 'E2')->first()->direction);
        $this->assertTrue(AttendanceEvent::where('external_ref', 'E6')->first()->raw_metadata['late_sync']);
        $run = DeviceSyncRun::first();
        $this->assertSame([6, 3, 1, 1, 1], [$run->fetched, $run->inserted, $run->duplicates, $run->unmapped, $run->errors]);
        $this->assertSame('partial', $run->status);
        $this->assertTrue(DomainEvent::where('type', 'DeviceSyncCompleted')->exists());
    }

    public function test_cross_tenant_token_writes_only_to_its_own_tenant(): void
    {
        [$uuid, $token] = $this->makeDevice();
        $this->bootTenant('beta');
        $this->dev($token, 'events', ['events' => [['device_employee_id' => '1001', 'timestamp' => '2026-10-07 09:00:00', 'event_id' => 'X']]])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, AttendanceEvent::count());
        $this->context(\App\Models\Tenant::where('slug', 'alpha')->value('id'));
        $this->assertSame(1, AttendanceEvent::count());
    }

    public function test_mapping_overlap_rejected_and_effective_dating(): void
    {
        [$uuid] = $this->makeDevice();
        $this->context($this->admin->tenant_id);
        $bob = $this->hire('Bob Stone');
        $this->signIn($this->admin);
        $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $bob->uuid, 'device_employee_id' => '1001', 'effective_from' => '2026-06-01'])->assertStatus(422);
        $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $bob->uuid, 'device_employee_id' => '1001', 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31'])->assertCreated();
    }

    public function test_unmapped_events_replay_once_mapping_exists(): void
    {
        [$uuid, $token] = $this->makeDevice();
        $this->dev($token, 'events', ['events' => [['device_employee_id' => '2002', 'timestamp' => '2026-10-07 09:00:00', 'event_id' => 'U1']]])->assertOk();
        $this->signIn($this->admin);
        $this->context($this->admin->tenant_id);
        $bob = $this->hire('Bob Stone');
        $res = $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $bob->uuid, 'device_employee_id' => '2002', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->assertSame(1, $res->json('replayed_events'));
    }

    public function test_providers_and_simulated_pull_sync(): void
    {
        $this->assertEqualsCanonicalizing(['generic_api', 'file_import', 'simulated'], array_keys(app(DeviceProviders::class)->all()));
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/attendance/devices', ['name' => 'Sim', 'type' => 'other', 'device_identifier' => 'SIM', 'timezone' => 'UTC', 'provider_key' => 'simulated',
            'config' => ['events' => [['device_employee_id' => '7', 'timestamp' => '2026-10-07T03:00:00Z', 'direction' => 'IN', 'event_id' => 'S1']]]])->assertCreated()->json('data.id');
        $this->assertTrue($this->getJson("/api/v1/attendance/devices/$uuid")->json('data.has_config'));
        $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $this->asha->uuid, 'device_employee_id' => '7', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->postJson("/api/v1/attendance/devices/$uuid/sync")->assertOk()->assertJsonPath('data.inserted', 1);
        $this->postJson("/api/v1/attendance/devices/$uuid/sync")->assertOk()->assertJsonPath('data.inserted', 0);
        $this->context($this->admin->tenant_id);
        AttendanceDevice::byUuid($uuid)->update(['config_encrypted' => null]);
        $d = AttendanceDevice::byUuid($uuid);
        $d->setConfig(['fail' => true]);
        $d->save();
        $run = app(DeviceSyncService::class)->pull($d);
        $this->assertSame('failed', $run->status);
        $this->assertTrue(DomainEvent::where('type', 'DeviceSyncFailed')->exists());
    }
}
