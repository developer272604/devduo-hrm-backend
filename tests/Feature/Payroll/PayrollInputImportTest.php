<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Models\PayrollImportRow;
use App\Domain\Payroll\Models\PayrollInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PayrollInputImportTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    public function test_input_import_preview_is_read_only_and_commit_preserves_approval_and_provenance(): void
    {
        $employee = $this->hire('Asha Rao');
        $group = $this->payrollGroup();
        $period = $this->payrollPeriod($group);
        $content = "employee_number,kind,component_code,amount\n{$employee->employee_number},bonus,SPOT_BONUS,1250.00\n{$employee->employee_number},bonus,SPOT_BONUS,1250.00\nUNKNOWN,bonus,SPOT_BONUS,1.00\n";
        $preview = $this->postJson('/api/v1/payroll/inputs/imports/preview', ['file' => UploadedFile::fake()->createWithContent('inputs.csv', $content), 'period_uuid' => $period->uuid, 'source' => 'bonus'])
            ->assertCreated()->assertJsonPath('status', 'previewed')->assertJsonPath('rows_total', 3)->assertJsonPath('rows_ok', 1)->assertJsonPath('rows_error', 1)->assertJsonPath('rows_duplicate', 1)->json();
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, PayrollInput::count());
        $this->postJson('/api/v1/payroll/inputs/imports/'.$preview['uuid'].'/commit')->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('rows_ok', 1);
        $this->context($this->admin->tenant_id);
        $input = PayrollInput::firstOrFail();
        $this->assertSame('pending', $input->status);
        $this->assertSame($employee->id, $input->employee_id);
        $this->assertSame('SPOT_BONUS', $input->component_code);
        $this->assertNotNull($input->import_id);
        $this->assertStringContainsString('import:', $input->source_ref);
        $this->getJson('/api/v1/payroll/inputs/imports')->assertOk()->assertJsonPath('data.0.uuid', $preview['uuid']);
        $this->getJson('/api/v1/payroll/inputs/imports/'.$preview['uuid'])->assertOk()->assertJsonPath('rows.0.status', 'committed');
        $this->postJson('/api/v1/payroll/inputs/imports/'.$preview['uuid'].'/retry')->assertOk()->assertJsonPath('rows_ok', 1)->assertJsonPath('rows.2.status', 'error');
        $this->postJson('/api/v1/payroll/inputs/imports/'.$preview['uuid'].'/commit')->assertOk()->assertJsonPath('rows_ok', 1);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, PayrollInput::count());
    }

    public function test_input_import_accepts_explicit_header_mapping_and_keeps_original_source_row(): void
    {
        $employee = $this->hire('Mina Shah');
        $group = $this->payrollGroup();
        $period = $this->payrollPeriod($group);
        $content = "Staff ID,Input Type,Pay Code,Value\n{$employee->employee_number},commission,SALES_COMMISSION,240.50\n";
        $preview = $this->postJson('/api/v1/payroll/inputs/imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('commission.csv', $content),
            'period_uuid' => $period->uuid,
            'source' => 'commission',
            'mapping' => ['employee_number' => 'Staff ID', 'kind' => 'Input Type', 'component_code' => 'Pay Code', 'amount' => 'Value', 'quantity' => ''],
        ])->assertCreated()->assertJsonPath('rows_ok', 1)->assertJsonPath('mapping.employee_number', 'staff_id')->json();

        $this->postJson('/api/v1/payroll/inputs/imports/'.$preview['uuid'].'/commit')->assertOk()->assertJsonPath('rows_ok', 1);
        $this->context($this->admin->tenant_id);
        $input = PayrollInput::firstOrFail();
        $this->assertSame('SALES_COMMISSION', $input->component_code);
        $importRow = PayrollImportRow::query()->where('import_id', $input->import_id)->firstOrFail();
        $this->assertSame($employee->employee_number, $importRow->raw['source_row']['Staff ID']);
        $this->assertSame($employee->employee_number, $importRow->raw['values']['employee_number']);
    }
}
