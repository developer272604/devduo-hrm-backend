<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Support\PayrollSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PayslipSelfServiceTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    private $run;

    private $employee;

    private $other;

    private $re;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $group = $this->payrollGroup();
        $this->run = $this->payrollRun($group, $this->payrollPeriod($group), ['status' => 'review']);
        $this->employee = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
        $this->other = $this->hire('Ravi Kumar', ['role' => 'Team member', 'email' => 'ravi@alpha.test']);
        $this->re = $this->runEmployee($this->run, $this->employee);
        app(ResultStore::class)->record($this->draft($this->sampleComponents()));
        $this->run->update(['status' => 'locked']);
    }

    private function draft(array $components, bool $correction = false): ResultDraft
    {
        return new ResultDraft($this->re->id, 'INR', $components, PayrollSettings::ENGINE_VERSION, '30', '30', controlledCorrection: $correction);
    }

    public function test_generate_publish_and_own_only_access(): void
    {
        $this->signIn($this->admin);
        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Payslip::count());
        $slip = Payslip::first();
        $this->assertSame('generated', $slip->status);
        $this->assertNotSame('62899.5000', DB::table('payslips')->value('snapshot'), 'snapshot is encrypted at rest');
        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertStatus(202); // idempotent
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Payslip::count());

        $me = $this->employee->person->user;
        $this->signIn($me)->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn($this->admin)->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/publish')->assertOk()->assertJsonPath('published', 1);
        $this->context($this->admin->tenant_id);

        $this->signIn($me)->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.net', '62899.5000');
        $this->getJson('/api/v1/payroll/me/payslips/'.$slip->uuid)->assertOk()->assertJsonPath('snapshot.totals.gross', '70000.0000')->assertJsonPath('snapshot.ytd.net', '62899.5000');
        $this->get('/api/v1/payroll/me/payslips/'.$slip->uuid.'/print')->assertOk()->assertHeader('content-type', 'text/html; charset=UTF-8')->assertSee('Net pay')->assertSee('@media print', false);

        $link = $this->postJson('/api/v1/payroll/me/payslips/'.$slip->uuid.'/download-link')->assertOk()->json('url');
        $this->get($link)->assertOk()->assertHeader('content-disposition');
        $this->get('/api/v1/payroll/me/payslips/'.$slip->uuid.'/download')->assertForbidden(); // unsigned
        $this->assertGreaterThanOrEqual(3, DB::table('payslip_access_logs')->where('payslip_id', $slip->id)->count());

        $this->signIn($this->other->person->user)->getJson('/api/v1/payroll/me/payslips/'.$slip->uuid)->assertNotFound();
        $this->get($link)->assertNotFound();
        $this->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn($me)->getJson('/api/v1/payroll/payslips/'.$slip->uuid)->assertForbidden(); // admin endpoint
    }

    public function test_correction_creates_new_version_and_keeps_history(): void
    {
        $this->signIn($this->admin)->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/publish')->assertOk();
        $this->context($this->admin->tenant_id);
        $changed = $this->sampleComponents();
        $changed[0]['amount'] = '52000.00';
        app(ResultStore::class)->record($this->draft($changed, true));

        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame([1, 2], Payslip::orderBy('version')->pluck('version')->all());
        $this->assertSame('published', Payslip::where('version', 1)->value('status'), 'employee keeps the published version until the correction is released');
        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/publish')->assertOk()->assertJsonPath('published', 1);
        $this->context($this->admin->tenant_id);
        $this->assertSame('superseded', Payslip::where('version', 1)->value('status'));
        $this->assertSame('published', Payslip::where('version', 2)->value('status'));
        $this->signIn($this->employee->person->user)->getJson('/api/v1/payroll/me/payslips')->assertJsonCount(2, 'data');
    }

    public function test_unlocked_run_cannot_produce_payslips_and_payment_release_rule_is_enforced(): void
    {
        $this->run->update(['status' => 'review']);
        $this->signIn($this->admin)->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->run->update(['status' => 'locked']);
        $this->postJson('/api/v1/payroll/runs/'.$this->run->uuid.'/payslips/generate')->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $tenant = \App\Models\Tenant::find($this->admin->tenant_id);
        $tenant->settings = ['payroll' => ['payslips' => ['publish_on' => 'payment']]];
        $tenant->save();
        $this->postJson('/api/v1/payroll/payslips/'.Payslip::first()->uuid.'/publish')->assertUnprocessable();
    }
}
