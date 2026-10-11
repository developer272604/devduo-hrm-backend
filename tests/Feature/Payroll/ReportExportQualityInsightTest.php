<?php

namespace Tests\Feature\Payroll;

use App\Domain\Insights\ActionCenter;
use App\Domain\Payroll\Models\CompensationLine;
use App\Domain\Payroll\Models\EmployeeCompensation;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Operations\PayrollEmployee360Provider;
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ReportExportQualityInsightTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase, SecurityTestSupport;

    private $group;

    private $run;

    private $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->group = $this->payrollGroup();
        $this->run = $this->payrollRun($this->group, $this->payrollPeriod($this->group));
        $this->employee = $this->hire('Asha Rao');
        $this->calc($this->run, $this->employee);
        $this->signIn($this->admin);
    }

    public function test_variance_and_register_reports_mask_amounts_without_results_permission(): void
    {
        $rows = $this->getJson('/api/v1/payroll/reports/register?run='.$this->run->uuid)->assertOk()->assertJsonPath('amounts_masked', false)->json('data');
        $this->assertSame('62899.50', $rows[0]['net']);
        $var = $this->getJson('/api/v1/payroll/reports/variance?run='.$this->run->uuid)->assertOk()->json('data');
        $this->assertSame('62899.50', $var[0]['current_net']);
        $this->getJson('/api/v1/payroll/reports/nope?run='.$this->run->uuid)->assertNotFound();

        $user = $this->userWithPermissions('rep@alpha.test', ['payroll.reports.view', 'payroll.export']);
        $masked = $this->signIn($user)->getJson('/api/v1/payroll/reports/register?run='.$this->run->uuid)->assertOk()->assertJsonPath('amounts_masked', true)->json('data');
        $this->assertSame('***', $masked[0]['net']);
        $this->assertSame('***', $masked[0]['gross']);
        $this->signIn($this->userWithPermissions('none@alpha.test', ['people.view']))->getJson('/api/v1/payroll/reports')->assertForbidden();
    }

    public function test_export_is_queued_audited_masked_by_permission_and_owner_only(): void
    {
        $payload = ['format' => 'csv', 'run' => $this->run->uuid];
        $this->postJson('/api/v1/payroll/reports/register/export', $payload)->assertUnprocessable()->assertJsonValidationErrors('purpose_code');
        $export = $this->postJson('/api/v1/payroll/reports/register/export', $payload + ['purpose_code' => 'audit'])->assertStatus(202)->json();
        $this->assertContains($export['status'], ['queued', 'ready']);

        $list = $this->getJson('/api/v1/payroll/exports')->assertOk()->json('data');
        $this->assertSame('ready', $list[0]['status'], 'the test queue runs the job synchronously');
        $this->assertFalse($list[0]['masked']);
        $csv = $this->get($list[0]['download'])->assertOk()->streamedContent();
        $this->assertStringContainsString('62899.50', $csv);

        $this->context($this->admin->tenant_id);
        $this->assertSame(2, DB::table('payroll_sensitive_access_logs')->where('data_type', 'export')->count()); // export + download
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.sensitive_download']);

        $user = $this->userWithPermissions('rep@alpha.test', ['payroll.reports.view', 'payroll.export']);
        $mine = $this->signIn($user)->postJson('/api/v1/payroll/reports/register/export', $payload + ['purpose_code' => 'audit'])->assertStatus(202)->json();
        $row = collect($this->getJson('/api/v1/payroll/exports')->json('data'))->firstWhere('uuid', $mine['uuid']);
        $this->assertTrue($row['masked']);
        $masked = $this->get($row['download'])->assertOk()->streamedContent();
        $this->assertStringContainsString('***', $masked);
        $this->assertStringNotContainsString('62899', $masked);

        $this->signIn($this->admin)->get('/api/v1/payroll/exports/'.$mine['uuid'].'/download')->assertNotFound();
    }

    public function test_quality_rules_and_action_center_insights_are_actionable(): void
    {
        $this->mapToGroup($this->employee, $this->group);          // in a group, no bank
        $loose = $this->hire('Ben Cole');                          // not in any group
        $this->context($this->admin->tenant_id);
        app(DataQualityEngine::class)->scan($this->admin->tenant_id, ['payroll_missing_group', 'payroll_invalid_bank']);
        $rules = DB::table('data_quality_findings')->where('tenant_id', $this->admin->tenant_id)->pluck('rule')->unique()->all();
        $this->assertContains('payroll_missing_group', $rules);
        $this->assertContains('payroll_invalid_bank', $rules);
        $finding = DB::table('data_quality_findings')->where('rule', 'payroll_missing_group')->first();
        $this->assertSame($loose->id, (int) $finding->employee_id);
        $this->assertSame('hr_confidential', $finding->classification);

        $this->payrollPeriod($this->group, '2026-10-01', '2026-10-31');
        $insights = collect(app(ActionCenter::class)->for($this->admin, 'payroll'));
        $this->assertNotEmpty($insights);
        $bank = $insights->first(fn ($i) => str_starts_with($i['key'], 'payroll.bank_missing'));
        $this->assertNotNull($bank);
        $this->assertSame('action_required', $bank['severity']);
        $this->assertNotEmpty($bank['actions'][0]['href']);
        $this->assertNotEmpty($bank['description']);
        $this->assertNotEmpty($bank['recommendation']);
        $this->assertSame('Asha Rao', $bank['sample'][0]['name']);
    }

    public function test_employee_360_masks_figures_without_a_stated_purpose_and_logs_reveals(): void
    {
        $component = SalaryComponent::create(['name' => 'Base pay', 'code' => 'BASE', 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-01-01', 'version' => 1]);
        $comp = EmployeeCompensation::create(['employee_id' => $this->employee->id, 'payroll_group_id' => $this->group->id, 'effective_from' => '2026-01-01', 'status' => 'approved', 'reason' => 'x', 'created_by' => $this->admin->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
        CompensationLine::create(['compensation_id' => $comp->id, 'component_id' => $component->id, 'amount' => '50000.00']);
        $provider = app(PayrollEmployee360Provider::class);
        $relation = ['self' => false, 'manager' => false];

        $sections = collect($provider->sections($this->admin, $this->employee->fresh(), $relation))->keyBy('key');
        $this->assertTrue($sections['compensation']['masked']);
        $this->assertTrue($sections['compensation']['purpose_required']);
        $this->assertSame('••••••', $sections['compensation']['items'][0]['value']);
        $this->assertArrayHasKey('compensation_history', $sections->all());
        $this->assertStringNotContainsString('50000', json_encode($sections['compensation_history']));

        request()->merge(['purpose_code' => 'compensation_review']);
        $revealed = collect($provider->sections($this->admin, $this->employee->fresh(), $relation))->keyBy('key');
        $this->assertFalse($revealed['compensation']['masked']);
        $this->assertSame('INR 50000.00', $revealed['compensation']['items'][0]['value']);
        $this->assertSame(1, DB::table('payroll_sensitive_access_logs')->where('action', 'reveal')->where('purpose_code', 'compensation_review')->count());

        $viewOnly = $this->userWithPermissions('v@alpha.test', ['payroll.compensation.view']);
        $none = $provider->sections($viewOnly, $this->employee->fresh(), $relation);
        $this->assertTrue(collect($none)->firstWhere('key', 'compensation')['masked']);
        $this->assertSame([], $provider->sections($this->userWithPermissions('w@alpha.test', ['people.view']), $this->employee->fresh(), $relation));
    }
}
