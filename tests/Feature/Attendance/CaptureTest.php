<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Capture\GeofenceService;
use App\Domain\Attendance\Capture\PunchService;
use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Models\Geofence;
use App\Domain\People\Models\Employee;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CaptureTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected Employee $asha;

    protected \App\Models\User $ashaUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->asha = $this->hire('Asha Rao', ['role' => 'Team member']);
        $this->ashaUser = \App\Models\User::where('email', 'asha@alpha.test')->first();
    }

    protected function settings(array $attendance): void
    {
        $t = Tenant::find($this->admin->tenant_id);
        $s = $t->settings;
        $s['attendance'] = $attendance;
        $t->settings = $s;
        $t->save();
    }

    protected function rules(array $rules): void
    {
        $this->app->instance('attendance.punch_rules', fn () => $rules);
    }

    protected function punchAs($user, array $body = [], array $headers = [])
    {
        $this->signIn($user);

        return $this->postJson('/api/v1/attendance/me/punch', $body, $headers);
    }

    public function test_server_time_is_authoritative_and_client_time_only_kept_as_metadata(): void
    {
        $res = $this->punchAs($this->ashaUser, ['client_time' => '2020-01-01T00:00:00Z'])->assertCreated();
        $this->assertSame('IN', $res->json('data.direction'));
        $this->context($this->admin->tenant_id);
        $e = AttendanceEvent::first();
        $this->assertSame('2026-10-07 04:30:00', $e->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2020-01-01T00:00:00Z', $e->raw_metadata['client_time']);
        $this->assertSame('Asia/Kolkata', $e->timezone);
        $this->assertSame('2026-10-07', $e->local_date->toDateString());
    }

    public function test_double_click_returns_existing_and_distinct_ins_are_both_stored(): void
    {
        $this->punchAs($this->ashaUser)->assertCreated();
        $dup = $this->punchAs($this->ashaUser)->assertOk();
        $this->assertTrue($dup->json('duplicate'));
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, AttendanceEvent::count());
        $this->travel(5)->minutes();
        $svc = app(PunchService::class);
        $svc->record(['employee' => $this->asha, 'source' => 'web', 'direction' => 'IN']);
        $this->assertSame(2, AttendanceEvent::count());
        $this->assertSame(['IN', 'IN'], AttendanceEvent::orderBy('id')->pluck('direction')->all());
    }

    public function test_toggle_direction_and_idempotency(): void
    {
        $this->punchAs($this->ashaUser, [], ['Idempotency-Key' => 'k1'])->assertCreated();
        $same = $this->punchAs($this->ashaUser, [], ['Idempotency-Key' => 'k1'])->assertOk();
        $this->assertSame('idempotency_key', $same->json('duplicate_reason'));
        $this->punchAs($this->ashaUser, ['direction' => 'OUT'], ['Idempotency-Key' => 'k1'])->assertStatus(409);
        $this->travel(2)->hours();
        $this->assertSame('OUT', $this->punchAs($this->ashaUser)->assertCreated()->json('data.direction'));
    }

    public function test_immutability(): void
    {
        $e = app(PunchService::class)->record(['employee' => $this->asha, 'source' => 'web'])->event;
        $e->update(['verification_status' => 'verified', 'processing_state' => 'processed']);
        try {
            $e->update(['direction' => 'OUT']);
            $this->fail('expected LogicException');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(\LogicException::class);
        $e->delete();
    }

    public function test_disabled_method_unemployed_and_locked_day(): void
    {
        $this->settings(['methods' => ['import']]);
        $this->punchAs($this->ashaUser)->assertStatus(403);
        $this->settings(['methods' => ['web', 'admin']]);
        $this->context($this->admin->tenant_id);
        $this->asha->forceFill(['status' => 'separated', 'separated_on' => '2026-09-30'])->save();
        $this->punchAs($this->ashaUser)->assertForbidden(); // not a working employment => no active record
        $this->asha->forceFill(['status' => 'active', 'separated_on' => null])->save();
        $this->app->instance(LockGuard::class, new class implements LockGuard
        {
            public function isLocked(int $e, string $d): bool
            {
                return true;
            }

            public function assertUnlocked(int $e, string $d): void
            {
                abort(423, 'Locked');
            }

            public function lockFor(int $e, string $d): ?array
            {
                return null;
            }
        });
        $this->punchAs($this->ashaUser)->assertStatus(423);
    }

    public function test_recalculation_requested_after_punch(): void
    {
        $spy = new class implements AttendanceRecalculator
        {
            public array $days = [];

            public function recalculateDay(int $e, string $d, string $t, ?int $by = null): ?int
            {
                $this->days[] = [$e, $d, $t];

                return 1;
            }

            public function recalculate(array $s, string $t, ?int $by = null, ?string $k = null): string
            {
                return 'run';
            }
        };
        $this->app->instance(AttendanceRecalculator::class, $spy);
        $this->punchAs($this->ashaUser)->assertCreated();
        $this->assertSame([[$this->asha->id, '2026-10-07', 'punch']], $spy->days);
    }

    public function test_void_requires_reason_audits_and_never_deletes(): void
    {
        $e = app(PunchService::class)->record(['employee' => $this->asha, 'source' => 'web'])->event;
        $this->signIn($this->admin);
        $this->postJson("/api/v1/attendance/punches/{$e->uuid}/void", [])->assertStatus(422);
        $this->postJson("/api/v1/attendance/punches/{$e->uuid}/void", ['reason' => 'Tapped by mistake'])->assertOk()->assertJsonPath('data.voided', true);
        $this->context($this->admin->tenant_id);
        $this->assertNotNull(AttendanceEvent::find($e->id)->voided_at);
        $this->assertTrue(AuditLog::where('action', 'punch_voided')->exists());
    }

    public function test_geofence_math_and_accuracy(): void
    {
        $d = GeofenceService::distanceMeters(13.0827, 80.2707, 13.0827, 80.2707 + 0.001);
        $this->assertEqualsWithDelta(108, $d, 3);
        $this->assertSame('inside', GeofenceService::judge(50, 10, 100));
        $this->assertSame('outside', GeofenceService::judge(300, 10, 100));
        $this->assertSame('unavailable', GeofenceService::judge(90, 200, 100));
        $this->assertSame('outside', GeofenceService::judge(400, 200, 100));
        $this->assertSame('unavailable', GeofenceService::judge(10, 5000, 100));
    }

    protected function fence(): void
    {
        Geofence::create(['name' => 'HQ', 'latitude' => 13.0827, 'longitude' => 80.2707, 'radius_meters' => 100, 'active' => true]);
    }

    public function test_modes_enforcement_and_remote_work(): void
    {
        $this->fence();
        $this->settings(['methods' => ['web'], 'location' => ['mode' => 'policy']]);
        $this->rules(['punch_restrictions' => ['geofence' => 'inside', 'capture_location' => true]]);
        $out = $this->punchAs($this->ashaUser, ['latitude' => 13.2, 'longitude' => 80.5, 'accuracy' => 10])->assertCreated();
        $this->assertSame('outside', $out->json('data.geofence_status'));
        $this->context($this->admin->tenant_id);
        $this->assertNotNull(AttendanceEvent::first()->latitude);

        $this->settings(['methods' => ['web'], 'location' => ['mode' => 'policy', 'enforcement' => 'block']]);
        $this->travel(2)->hours();
        $this->punchAs($this->ashaUser, ['latitude' => 13.2, 'longitude' => 80.5, 'accuracy' => 10])->assertStatus(422);

        $this->rules(['punch_restrictions' => ['geofence' => 'remote_work', 'capture_location' => true]]);
        \DB::table('on_duty_requests')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) \Str::uuid(), 'employee_id' => $this->asha->id, 'type' => 'remote_work', 'starts_at' => '2026-10-07 00:00:00',
            'ends_at' => '2026-10-07 23:00:00', 'timezone' => 'Asia/Kolkata', 'start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'reason' => 'WFH', 'status' => 'approved', 'requested_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->punchAs($this->ashaUser, ['latitude' => 13.2, 'longitude' => 80.5, 'accuracy' => 10])->assertCreated()->assertJsonPath('data.geofence_status', 'not_required');
    }

    public function test_coordinates_not_captured_when_tenant_mode_off(): void
    {
        $this->rules(['punch_restrictions' => ['capture_location' => true]]);
        $this->punchAs($this->ashaUser, ['latitude' => 13.0, 'longitude' => 80.0, 'accuracy' => 5])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertNull(AttendanceEvent::first()->latitude);
    }

    public function test_location_hidden_without_permission_and_reveal_is_audited_then_purged(): void
    {
        $this->settings(['methods' => ['web'], 'location' => ['mode' => 'policy', 'retention_days' => 30]]);
        $this->rules(['punch_restrictions' => ['capture_location' => true]]);
        $this->punchAs($this->ashaUser, ['latitude' => 13.0, 'longitude' => 80.0, 'accuracy' => 5])->assertCreated()->assertJsonPath('data.location.latitude', 13);
        $this->context($this->admin->tenant_id);
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $this->context($this->admin->tenant_id);
        $this->asha->relationships()->count();
        $this->signIn(\App\Models\User::where('email', 'maya@alpha.test')->first());
        $this->getJson('/api/v1/attendance/punches')->assertOk()->assertJsonPath('data.0.location', null);
        $this->signIn($this->admin);
        $this->getJson('/api/v1/attendance/punches')->assertOk()->assertJsonPath('data.0.location.latitude', 13);
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('action', 'location_viewed')->exists());

        $this->travel(40)->days();
        $this->artisan('attendance:purge-location-data')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $e = AttendanceEvent::first();
        $this->assertNull($e->latitude);
        $this->assertNotNull($e->punched_at);
    }

    public function test_photo_validation_and_storage(): void
    {
        Storage::fake('attendance_private');
        config(['filesystems.disks.attendance_private' => ['driver' => 'local', 'root' => Storage::disk('attendance_private')->path('')]]);
        $this->rules(['punch_restrictions' => ['require_photo' => true]]);
        $this->punchAs($this->ashaUser)->assertStatus(422);
        $bad = UploadedFile::fake()->createWithContent('x.jpg', 'not an image');
        $this->punchAs($this->ashaUser, ['photo' => $bad])->assertStatus(422);
        $img = UploadedFile::fake()->image('p.jpg', 2000, 1500);
        $res = $this->punchAs($this->ashaUser, ['photo' => $img])->assertCreated();
        $this->assertTrue($res->json('data.has_photo'));
        $this->assertSame('pending', $res->json('data.verification_status'));
        $this->signIn($this->admin);
        $this->postJson('/api/v1/attendance/punches/'.$res->json('data.id').'/verify', ['status' => 'verified'])->assertOk()->assertJsonPath('data.verification_status', 'verified');
    }
}
