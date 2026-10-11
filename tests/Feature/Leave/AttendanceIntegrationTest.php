<?php

namespace Tests\Feature\Leave;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Scheduling\UnavailabilityProvider;
use App\Domain\Events\DomainEvent;
use App\Domain\Leave\Integration\LedgerLeaveDataProvider;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceIntegrationTest extends TestCase
{
    use RefreshDatabase, RequestFixtures;

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLeave();
        $this->account($this->emp, $this->annual, '10');
        $test = $this;
        $this->app->instance(AttendanceRecalculator::class, new class($test) implements AttendanceRecalculator
        {
            public function __construct(private $t) {}

            public function recalculateDay(int $employeeId, string $date, string $trigger, ?int $requestedBy = null): ?int
            {
                $this->t->calls[] = [$employeeId, $date, $trigger];

                return 1;
            }

            public function recalculate(array $scope, string $trigger, ?int $requestedBy = null, ?string $dedupeKey = null): string
            {
                return 'x';
            }
        });
    }

    public function test_real_provider_is_bound_and_approval_recalculates_attendance_and_cancellation_reverses(): void
    {
        $this->assertInstanceOf(LedgerLeaveDataProvider::class, app(LeaveDataProvider::class));
        $uuid = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->json('data.uuid');
        $this->assertSame([], $this->calls, 'pending leave never recalculates attendance');
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $this->assertEqualsCanonicalizing(['2026-10-12', '2026-10-13'], array_column($this->calls, 1));
        $this->assertSame('recalculation', $this->calls[0][2]);
        $event = DomainEvent::where('type', LeaveEvents::COVERAGE_CHANGED)->first();
        $this->assertSame($this->emp->id, $event->payload['employee_id']);
        $this->assertStringNotContainsString('Family', json_encode($event->payload), 'no reasons in events');

        $map = app(LeaveDataProvider::class)->coverageForRange([$this->emp->id, $this->peer->id], '2026-10-01', '2026-10-31');
        $this->assertSame(['2026-10-12', '2026-10-13'], array_map(fn ($k) => explode('|', $k)[1], array_keys($map)));
        $this->assertSame(['minutes', 'portion', 'reference', 'paid'], array_keys($map[$this->emp->id.'|2026-10-12']));
        $this->assertSame($uuid, $map[$this->emp->id.'|2026-10-12']['reference']);
        $this->assertSame('Approved leave', app(UnavailabilityProvider::class)->unavailable([$this->emp->id], '2026-10-12', '2026-10-13')[$this->emp->id.'|2026-10-12']);

        $this->calls = [];
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", [])->assertOk();
        $this->assertEqualsCanonicalizing(['2026-10-12', '2026-10-13'], array_column($this->calls, 1));
        $this->assertSame([], app(LeaveDataProvider::class)->coverageForRange([$this->emp->id], '2026-10-01', '2026-10-31'));
    }

    public function test_half_day_and_unpaid_leave_are_normalized_for_attendance(): void
    {
        $unpaid = LeaveType::create(['name' => 'Unpaid', 'code' => 'UP', 'category' => 'unpaid', 'unit' => 'half_day', 'tracks_balance' => false]);
        $uuid = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $unpaid->uuid, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'portion' => 'second_half']))->assertCreated()->json('data.uuid');
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $cov = app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12');
        $this->assertSame('second_half', $cov['portion']);
        $this->assertSame(240, $cov['minutes']);
        $this->assertFalse($cov['paid']);
    }
}
