<?php
namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Processing\DayProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures;
    protected function setUp(): void { parent::setUp();$this->bootTenant(); }

    public function test_domain_contracts_are_bound_and_unlinked_user_has_clear_empty_state(): void
    {
        $this->assertInstanceOf(AttendanceRecalculator::class,app(AttendanceRecalculator::class));
        $this->assertInstanceOf(LockGuard::class,app(LockGuard::class));
        $this->signIn($this->admin)->getJson('/api/v1/attendance/me')->assertOk()->assertJsonPath('employee',null);
        $this->postJson('/api/v1/attendance/punch',['direction'=>'IN','idempotency_key'=>'unlinked'])->assertStatus(422);
    }

    public function test_employee_can_read_own_history_but_not_another_employee(): void
    {
        $one=$this->hire('Asha Rao',['role'=>'Team member']);$two=$this->hire('Bina Rao');
        foreach([$one,$two] as $employee) app(DayProcessor::class)->process($employee->id,'2026-10-06','manual',$this->admin->id);
        $otherDay=AttendanceDay::query()->where('employee_id',$two->id)->firstOrFail();
        $this->signIn($one->person->user);
        $this->getJson('/api/v1/attendance/days?from=2026-10-01&to=2026-10-07')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.employee_uuid',$one->uuid);
        $this->getJson('/api/v1/attendance/days/'.$otherDay->uuid)->assertNotFound();
    }

    public function test_punch_uses_server_time_and_retries_do_not_duplicate_events(): void
    {
        $employee=$this->hire('Asha Rao',['role'=>'Team member']);$this->signIn($employee->person->user);
        $body=['direction'=>'IN','idempotency_key'=>'same-punch','at'=>'2001-01-01T00:00:00Z'];
        $first=$this->postJson('/api/v1/attendance/punch',$body)->assertCreated()->assertJsonPath('duplicate',false);
        $this->postJson('/api/v1/attendance/punch',$body)->assertOk()->assertJsonPath('duplicate',true)->assertJsonPath('event.uuid',$first->json('event.uuid'));
        $this->assertDatabaseCount('attendance_events',1);
        $this->assertDatabaseHas('attendance_events',['employee_id'=>$employee->id,'local_date'=>'2026-10-07']);
    }
}
