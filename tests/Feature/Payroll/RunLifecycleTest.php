<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Contracts\Dto\PayrollInputBatch;
use App\Domain\Payroll\Contracts\Dto\PayrollInputLine;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Contracts\PayrollInputProvider;
use App\Domain\Payroll\Integration\PayrollInputRegistry;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollLock;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollValidationIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class RunLifecycleTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    private $group;

    private $period;

    private $asha;

    private $ravi;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->group = $this->payrollGroup();
        $this->period = $this->payrollPeriod($this->group);
        $this->asha = $this->hire('Asha Rao');
        $this->ravi = $this->hire('Ravi Kumar', ['joined_on' => '2026-09-16']); // joiner mid period
        foreach ([$this->asha, $this->ravi] as $e) {
            DB::table('employee_policy_references')->insert(['tenant_id' => $this->admin->tenant_id, 'employee_id' => $e->id, 'kind' => 'payroll_group', 'reference_id' => $this->group->id,
                'effective_from' => '2026-01-01', 'effective_to' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test', 'Checker');
        $this->fakeResolvers();
    }

    private function fakeResolvers(): void
    {
        $this->app->instance(CompensationResolver::class, new class implements CompensationResolver
        {
            public function current(int $employeeId, string $date): ?array
            {
                return $this->between($employeeId, $date, $date)[0] ?? null;
            }

            public function between(int $employeeId, string $from, string $to): array
            {
                $line = fn ($code, $kind, $amount, $meta) => ['component_id' => null, 'component_code' => $code, 'component_version' => 1, 'kind' => $kind, 'name' => $code, 'amount' => $amount, 'formula' => null, 'sequence' => 10, 'meta' => $meta];

                return [['compensation_id' => null, 'structure_id' => null, 'currency' => 'INR', 'effective_from' => '2026-01-01', 'effective_to' => null, 'base_pay' => '30000.00', 'hourly_rate' => '100.00',
                    'lines' => [$line('BASE', 'earning', '30000.00', ['proration' => 'payable_days']), $line('PF', 'deduction', '1800.00', ['proration' => 'none'])]]];
            }
        });
        $ashaUuid = $this->asha->uuid;
        $provider = fn (string $source, array $lines) => new class($source, $lines) implements PayrollInputProvider
        {
            public function __construct(private string $src, private array $lines) {}

            public function source(): string
            {
                return $this->src;
            }

            public function collect(PayrollInputQuery $query): PayrollInputBatch
            {
                return new PayrollInputBatch($this->src, $query->from, $query->to, $this->lines, [], true, $this->src.':test');
            }
        };
        $registry = app(PayrollInputRegistry::class);
        $registry->register('attendance', $provider('attendance', [
            new PayrollInputLine('attendance', 'payable_days', $ashaUuid, null, '28.00', 'days', 'attendance:IN_MONTHLY:2026-09-01:2026-09-30'),
            new PayrollInputLine('attendance', 'approved_overtime_minutes', $ashaUuid, null, '120', 'minutes', 'attendance:ot:2026-09'),
        ]));
        $registry->register('leave', $provider('leave', []));
    }

    private function api(User $user, string $method, string $path, array $body = [], array $headers = [])
    {
        $this->signIn($user);
        $response = $this->json($method, '/api/v1/payroll/'.$path, $body, $headers);
        $this->context($user->tenant_id);

        return $response;
    }

    private function createRun(): string
    {
        $r = $this->api($this->admin, 'POST', 'runs', ['period_uuid' => $this->period->uuid], ['Idempotency-Key' => (string) Str::uuid()]);
        $r->assertCreated();

        return $r->json('uuid');
    }

    private function calculated(): string
    {
        $uuid = $this->createRun();
        $this->api($this->admin, 'POST', "runs/$uuid/collect-inputs")->assertOk()->assertJsonPath('created', 2);
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(202);

        return $uuid;
    }

    public function test_create_is_idempotent_builds_roster_with_joiner_and_requires_permission(): void
    {
        $key = (string) Str::uuid();
        $body = ['period_uuid' => $this->period->uuid];
        $first = $this->api($this->admin, 'POST', 'runs', $body, ['Idempotency-Key' => $key])->assertCreated();
        $again = $this->api($this->admin, 'POST', 'runs', $body, ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($first->json('uuid'), $again->json('uuid'));
        $this->assertSame(1, PayrollRun::count());
        $this->assertSame('inputs', $first->json('status'));
        $this->assertSame(2, $first->json('employees_total'));
        $this->api($this->admin, 'POST', 'runs', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409); // one regular run per period
        $emp = $this->api($this->admin, 'GET', 'runs/'.$first->json('uuid').'/employees')->assertOk();
        $joiner = collect($emp->json('data'))->firstWhere('employee_uuid', $this->ravi->uuid);
        $this->assertTrue($joiner['snapshot']['joiner']);
        $this->assertSame('2026-09-16', $joiner['snapshot']['pay_from']);

        $member = $this->userWithRole('Team member', 'tm@alpha.test');
        $this->api($member, 'GET', 'runs')->assertForbidden();
    }

    public function test_calculation_writes_versioned_results_prorated_and_reproducible(): void
    {
        $uuid = $this->calculated();
        $run = $this->api($this->admin, 'GET', "runs/$uuid")->assertOk();
        $this->assertSame('review', $run->json('status'));
        $this->assertSame(2, $run->json('control_totals.employees'));

        $asha = $this->api($this->admin, 'GET', "runs/$uuid/results/{$this->asha->uuid}")->assertOk();
        $by = collect($asha->json('current.components'))->keyBy('code');
        $this->assertSame('28000.0000', $by['BASE']['amount']);   // attendance payable 28/30
        $this->assertSame('200.0000', $by['OVERTIME']['amount']); // 2h * 100
        $this->assertSame('1800.0000', $by['PF']['amount']);
        $this->assertSame('26400.0000', $asha->json('current.net'));
        $ravi = $this->api($this->admin, 'GET', "runs/$uuid/results/{$this->ravi->uuid}")->json('current');
        $this->assertSame('15000.0000', collect($ravi['components'])->keyBy('code')['BASE']['amount']); // 15/30 days
        $this->assertSame('15.0000', $ravi['payable_days']);

        $proof = $this->api($this->admin, 'POST', "runs/$uuid/results/{$this->asha->uuid}/verify")->assertOk();
        $this->assertTrue($proof->json('reproducible'));
        $this->assertTrue($proof->json('intact'));

        // recalculating with identical data is idempotent (same checksum, no new version); changed data makes version 2
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(202);
        $this->assertSame(1, $this->api($this->admin, 'GET', "runs/$uuid/results/{$this->asha->uuid}")->json('current.version'));
    }

    public function test_pending_input_blocks_approval_until_a_different_person_approves_it(): void
    {
        $uuid = $this->calculated();
        $input = $this->api($this->admin, 'POST', 'inputs', ['employee_uuid' => $this->asha->uuid, 'period_uuid' => $this->period->uuid, 'source' => 'bonus', 'kind' => 'bonus', 'component_code' => 'BONUS', 'amount' => '2500.00'])->assertCreated();
        $this->assertSame('pending', $input->json('status'));
        $this->api($this->admin, 'POST', "runs/$uuid/submit")->assertStatus(422); // unapproved_input blocking
        $this->assertTrue(PayrollValidationIssue::where('code', 'unapproved_input')->where('severity', 'blocking')->exists());

        $this->api($this->admin, 'POST', 'inputs/'.$input->json('uuid').'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->api($this->checker, 'POST', 'inputs/'.$input->json('uuid').'/decision', ['decision' => 'approved'])->assertOk();
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(202);
        $asha = $this->api($this->admin, 'GET', "runs/$uuid/results/{$this->asha->uuid}")->json('current');
        $this->assertSame(2, $asha['version']);
        $this->assertSame('2500.0000', collect($asha['components'])->keyBy('code')['BONUS']['amount']);
        $this->api($this->admin, 'POST', "runs/$uuid/submit")->assertOk()->assertJsonPath('status', 'approval');
    }

    public function test_maker_checker_lock_immutability_and_controlled_reopen(): void
    {
        $uuid = $this->calculated();
        $this->api($this->admin, 'POST', "runs/$uuid/submit")->assertOk();
        $this->api($this->admin, 'POST', "runs/$uuid/approve")->assertForbidden(); // maker cannot approve
        $this->api($this->checker, 'POST', "runs/$uuid/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->api($this->checker, 'POST', "runs/$uuid/lock")->assertOk()->assertJsonPath('status', 'locked');
        $this->assertSame('locked', DB::table('payroll_periods')->where('id', $this->period->id)->value('state'));

        $before = PayrollCalculationResult::where('is_current', true)->pluck('checksum', 'id')->all();
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(423); // locked results never change
        $this->assertSame($before, PayrollCalculationResult::where('is_current', true)->pluck('checksum', 'id')->all());
        $this->api($this->admin, 'POST', 'inputs', ['employee_uuid' => $this->asha->uuid, 'period_uuid' => $this->period->uuid, 'source' => 'bonus', 'kind' => 'bonus', 'amount' => '1.00'])->assertStatus(423);

        $this->api($this->admin, 'POST', "runs/$uuid/reopen-request", ['reason' => 'x'])->assertStatus(422);
        $this->api($this->admin, 'POST', "runs/$uuid/reopen-request", ['reason' => 'Attendance correction received late'])->assertOk();
        $this->api($this->admin, 'POST', "runs/$uuid/reopen-decision", ['decision' => 'approved'])->assertForbidden();
        $this->api($this->checker, 'POST', "runs/$uuid/reopen-decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'reopened');
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(202);
        $this->assertSame('review', $this->api($this->admin, 'GET', "runs/$uuid")->json('status'));
        $lock = PayrollLock::where('status', 'released')->first();
        $this->assertSame('recalculation', $lock->post_lock_changes[0]['change']);
    }

    public function test_exclusions_holds_and_failed_employee_isolation(): void
    {
        $uuid = $this->createRun();
        $this->api($this->admin, 'PATCH', "runs/$uuid/employees/{$this->ravi->uuid}", ['action' => 'held', 'reason' => 'Bank details pending'])->assertOk()->assertJsonPath('status', 'held');
        $this->app->instance(CompensationResolver::class, new class implements CompensationResolver
        {
            public function current(int $employeeId, string $date): ?array
            {
                return null;
            }

            public function between(int $employeeId, string $from, string $to): array
            {
                return []; // nobody has approved compensation
            }
        });
        $this->api($this->admin, 'POST', "runs/$uuid/calculate")->assertStatus(202);
        $run = $this->api($this->admin, 'GET', "runs/$uuid")->assertOk();
        $this->assertSame('review', $run->json('status')); // a failing employee does not fail the run
        $this->assertSame(1, $run->json('employees_failed'));
        $this->assertTrue(PayrollValidationIssue::where('code', 'no_compensation')->where('severity', 'blocking')->exists());
        $this->api($this->admin, 'POST', "runs/$uuid/submit")->assertStatus(422);
    }

    public function test_adjustments_are_idempotent_and_require_independent_approval(): void
    {
        $key = (string) Str::uuid();
        $payload = ['employee_uuid' => $this->asha->uuid, 'kind' => 'retro', 'settlement_period_uuid' => $this->period->uuid, 'origin_period_uuid' => $this->period->uuid,
            'component_code' => 'BASE', 'amount' => '125.25', 'reason' => 'Approved backdated salary change'];
        $first = $this->api($this->admin, 'POST', 'adjustments', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('status', 'pending')->json('uuid');
        $this->api($this->admin, 'POST', 'adjustments', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('uuid', $first);
        $this->api($this->admin, 'POST', 'adjustments/'.$first.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->api($this->checker, 'POST', 'adjustments/'.$first.'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $reversal = $this->api($this->admin, 'POST', 'adjustments/'.$first.'/reverse', ['settlement_period_uuid' => $this->period->uuid, 'reason' => 'Reverse duplicated retro amount'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('amount', '-125.2500')->assertJsonPath('reverses_uuid', $first)->json('uuid');
        $this->api($this->checker, 'POST', 'adjustments/'.$reversal.'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->context($this->admin->tenant_id);
        $this->assertSame('reversed', PayrollAdjustment::where('uuid', $first)->value('status'));
    }
}
