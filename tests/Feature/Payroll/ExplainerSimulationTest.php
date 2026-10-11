<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollSimulationRun;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Simulation\SimulationService;
use App\Domain\Payroll\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ExplainerSimulationTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase, SecurityTestSupport;

    private $employee;

    private $augRun;

    private $sepRun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $group = $this->payrollGroup();
        $this->augRun = $this->payrollRun($group, $this->payrollPeriod($group, '2026-08-01', '2026-08-31'));
        $this->sepRun = $this->payrollRun($group, $this->payrollPeriod($group, '2026-09-01', '2026-09-30'));
        $this->employee = $this->hire('Asha Rao');
        $this->calc($this->augRun, $this->employee);
        $this->augRun->update(['status' => 'locked']);
        $changed = $this->sampleComponents();
        $changed[0]['amount'] = '52000.00';   // base +2000
        $changed = array_values(array_filter($changed, fn ($c) => $c['component_code'] !== 'LOAN')); // loan recovery ended: +2000
        $this->calc($this->sepRun, $this->employee, $changed);
        $this->signIn($this->admin);
    }

    private const PURPOSE = ['purpose_code' => 'audit'];

    public function test_explainer_reads_stored_results_reconciles_and_logs_sensitive_access(): void
    {
        $url = '/api/v1/payroll/explain/employees/'.$this->employee->uuid.'?run='.$this->sepRun->uuid;
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('purpose_code');

        $json = $this->getJson($url.'&purpose_code=audit')->assertOk()->json();
        $this->assertTrue($json['reconciles']);
        $this->assertTrue($json['reproducible']);
        $this->assertSame('66899.50', $json['totals']['net']); // 72000 + 1200 - 1800 - 4500.50
        $this->assertSame('72000.00', $json['totals']['gross']);
        $this->assertSame(1, $json['result']['version']);
        $this->assertCount(6, $json['components']);
        $this->assertStringContainsString('= 66899.50', $json['answers']['why_net']);
        $this->assertSame('statutory', collect($json['answers']['deductions'])->firstWhere('name', 'Income tax')['origin']);

        $this->context($this->admin->tenant_id);
        $log = DB::table('payroll_sensitive_access_logs')->where('data_type', 'payroll_result')->first();
        $this->assertNotNull($log);
        $this->assertSame('audit', $log->purpose_code);
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertStringNotContainsString('66899', json_encode($log));

        $hr = $this->userWithPermissions('viewer@alpha.test', ['payroll.view']);
        $this->signIn($hr)->getJson($url.'&purpose_code=audit')->assertForbidden();
        $this->context($this->admin->tenant_id);
        $this->signIn($this->admin)->getJson('/api/v1/payroll/explain/employees/'.\Illuminate\Support\Str::uuid().'?purpose_code=audit')->assertNotFound();
    }

    public function test_difference_views_split_the_net_change_into_drivers_that_add_up(): void
    {
        $emp = $this->getJson('/api/v1/payroll/difference/employees/'.$this->employee->uuid.'?purpose_code=audit')->assertOk()->json();
        $this->assertSame('2026-09', $emp['current_period']);
        $this->assertSame('2026-08', $emp['previous_period']);
        $this->assertSame('62899.50', $emp['previous_net']);
        $this->assertSame('66899.50', $emp['current_net']);
        $this->assertSame('4000.00', $emp['difference']);
        $this->assertTrue($emp['reconciles']);
        $this->assertSame('2000.00', collect($emp['drivers'])->firstWhere('driver', 'Loan Recovery')['effect']);

        $run = $this->getJson('/api/v1/payroll/difference/runs/'.$this->sepRun->uuid.'?purpose_code=audit')->assertOk()->json();
        $this->assertSame($this->augRun->uuid, $run['against']['uuid']);
        $this->assertSame('4000.00', $run['difference']);
        $this->assertTrue($run['reconciles']);
        $this->assertSame(1, $run['employees_compared']);
        $this->assertSame($this->employee->uuid, $run['top_movers'][0]['employee_uuid']);

        // a recalculation creates a new version; the "recalculation" mode compares it with the one it superseded
        $more = $this->sampleComponents();
        $more[0]['amount'] = '53000.00';
        $this->context($this->admin->tenant_id);
        $this->calc($this->sepRun, $this->employee, array_values(array_filter($more, fn ($c) => $c['component_code'] !== 'LOAN')));
        $re = $this->getJson('/api/v1/payroll/difference/runs/'.$this->sepRun->uuid.'?mode=recalculation&purpose_code=audit')->assertOk()->json();
        $this->assertSame('1000.00', $re['difference']);
        $this->assertTrue($re['reconciles']);
    }

    public function test_simulation_estimates_from_stored_results_never_touches_live_payroll_and_masks_for_non_reveal_users(): void
    {
        $before = PayrollCalculationResult::count();
        $res = $this->postJson('/api/v1/payroll/simulations', ['kind' => 'salary_revision', 'employees' => [$this->employee->uuid], 'params' => ['percent' => '10'], 'title' => 'Annual'])->assertCreated()->json();
        $this->assertSame('estimate', $res['engine']);
        $this->assertFalse($res['mutates_live_payroll']);
        $this->assertNotEmpty($res['limitations']);
        // Sept result: BASE 52000 + HRA 20000 are compensation earnings -> +10% = +7200 gross and net
        $this->assertTrue(Decimal::eq($res['employees'][0]['difference']['net'], '7200'));
        $this->assertFalse($res['masked']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before, PayrollCalculationResult::count());
        $this->assertSame(1, PayrollSimulationRun::count());
        $this->assertNotSame('', (string) DB::table('payroll_simulation_runs')->value('result'));
        $this->assertStringNotContainsString('7200', (string) DB::table('payroll_simulation_runs')->value('result'), 'result is stored encrypted');

        $lop = $this->postJson('/api/v1/payroll/simulations', ['kind' => 'lop', 'employees' => [$this->employee->uuid], 'params' => ['days' => '3']])->assertCreated()->json();
        $this->assertTrue(Decimal::eq($lop['employees'][0]['difference']['gross'], '-7200'));   // 72000 * 3 / 30
        $this->postJson('/api/v1/payroll/simulations', ['kind' => 'bonus', 'employees' => [$this->employee->uuid], 'params' => ['amount' => '1000.50']])->assertCreated();
        $this->postJson('/api/v1/payroll/simulations', ['kind' => 'lop', 'employees' => [$this->employee->uuid], 'params' => ['days' => '45']])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/simulations', ['kind' => 'salary_revision', 'employees' => [$this->employee->uuid], 'params' => ['percent' => 'abc']])->assertUnprocessable();

        $uuid = $res['uuid'];
        $this->getJson('/api/v1/payroll/simulations/'.$uuid)->assertOk()->assertJsonPath('masked', false);
        $this->context($this->admin->tenant_id);
        $masked = app(SimulationService::class)->present(PayrollSimulationRun::first()->result, $uuid, false);
        $this->assertTrue($masked['masked']);
        $this->assertNull($masked['employees'][0]['scenario']);
        $this->assertNull($masked['totals']['difference_net']);

        // the pre-existing formula sandbox keeps its path and shape
        $this->postJson('/api/v1/payroll/simulate', ['expression' => 'BASE / 3 * 3', 'variables' => ['BASE' => '100.01']])->assertOk()->assertJsonPath('persisted', false);
    }

    public function test_simulation_uses_the_employee_calculator_when_it_is_bound(): void
    {
        $this->app->bind(EmployeeCalculator::class, fn () => new class implements EmployeeCalculator
        {
            public function calculate(array $request): ResultDraft
            {
                return new ResultDraft(0, 'INR', [['component_code' => 'BASE', 'name' => 'Base', 'kind' => 'earning', 'amount' => '60000.00', 'origin' => 'compensation']], '1.0.0');
            }
        });
        $res = $this->postJson('/api/v1/payroll/simulations', ['kind' => 'salary_revision', 'employees' => [$this->employee->uuid], 'params' => ['percent' => '5']])->assertCreated()->json();
        $this->assertSame('employee_calculator', $res['engine']);
        $this->assertSame([], $res['limitations']);
        $this->assertTrue(Decimal::eq($res['employees'][0]['scenario']['net'], '60000'));
    }
}
