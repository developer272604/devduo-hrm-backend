<?php

namespace Tests\Feature\Payroll;

use App\Domain\Access\FieldRegistry;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Payroll\Calculation\FormulaEngine;
use App\Domain\Payroll\Contracts\Dto\PaymentBatchData;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Integration\AttendancePayrollInputProvider;
use App\Domain\Payroll\Integration\PayrollInputRegistry;
use App\Domain\Payroll\Models\CountryPack;
use App\Domain\Payroll\Models\CountryPackVersion;
use App\Domain\Payroll\Models\PayrollPeriodTransition;
use App\Domain\Payroll\Payments\GenericCsvPaymentFormat;
use App\Domain\Payroll\Support\AccountingMapperRegistry;
use App\Domain\Payroll\Support\PaymentFormatRegistry;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PayrollContractsTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    private function permissionsOf(string $role): array
    {
        return DB::table('permission_role')->join('roles', 'roles.id', '=', 'permission_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.tenant_id', $this->admin->tenant_id)->where('roles.name', $role)->pluck('permissions.key')->all();
    }

    public function test_payroll_permissions_are_registered_and_sensitive_by_default(): void
    {
        $catalog = array_keys(array_filter(WorkspaceProvisioner::PERMISSIONS, fn ($v, $k) => str_starts_with($k, 'payroll.'), ARRAY_FILTER_USE_BOTH));
        $this->assertGreaterThan(35, count($catalog));
        $admin = $this->permissionsOf('Workspace administrator');
        $this->assertSame([], array_diff($catalog, $admin), 'workspace administrator holds every payroll key');

        foreach (['HR administrator', 'Manager', 'Team member'] as $role) {
            $payroll = array_values(array_filter($this->permissionsOf($role), fn ($k) => str_starts_with($k, 'payroll.')));
            sort($payroll);
            $self = WorkspaceProvisioner::PAYROLL_SELF;
            sort($self);
            $this->assertSame($self, $payroll, "$role gets only own-data payroll keys");
        }
        $this->assertNotContains('payroll.compensation.view', $this->permissionsOf('Manager'));
    }

    public function test_grant_migration_gives_existing_workspaces_the_new_keys(): void
    {
        $roleId = DB::table('roles')->where('tenant_id', $this->admin->tenant_id)->where('name', 'Team member')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'payroll.payslips.view_own')->value('id');
        DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        (require database_path('migrations/2026_10_14_000002_grant_payroll_permissions.php'))->up();
        $this->assertContains('payroll.payslips.view_own', $this->permissionsOf('Team member'));
    }

    public function test_compensation_and_payroll_fields_are_highly_sensitive(): void
    {
        foreach (['compensation.base_pay', 'compensation.history', 'payroll.net_pay', 'payslip.snapshot', 'loan.records', 'benefit.enrollment'] as $field) {
            $this->assertTrue(FieldRegistry::known($field), $field);
            $this->assertSame('highly_sensitive', FieldRegistry::FIELDS[$field][0], $field);
            $this->assertSame('compensation', FieldRegistry::group($field));
        }
        $this->assertSame('none', FieldRegistry::SELF_EDIT_DEFAULTS['compensation']);
    }

    public function test_settings_defaults_are_conservative_and_tenant_overridable(): void
    {
        $this->assertSame('block', PayrollSettings::get('calculation.negative_net'));
        $this->assertTrue(PayrollSettings::get('approvals.run_checker'));
        $this->assertFalse(PayrollSettings::get('privacy.managers_see_compensation'));
        $this->assertSame('3', (string) PayrollSettings::get('exports.retention_days'));
        PayrollSettings::save(['calculation' => ['negative_net' => 'warn']]);
        $this->assertSame('warn', PayrollSettings::get('calculation.negative_net'));
        $this->assertSame('half_up', PayrollSettings::get('calculation.rounding_mode'), 'untouched keys keep defaults');
    }

    public function test_formula_engine_discovers_variables_validates_and_traces(): void
    {
        $engine = app(FormulaEvaluator::class);
        $this->assertInstanceOf(FormulaEngine::class, $engine);
        $this->assertSame(['BASE', 'WORKING_DAYS', 'PAYABLE_DAYS'], $engine->references('MIN(BASE, 15000) / WORKING_DAYS * PAYABLE_DAYS'));
        $this->assertSame([], $engine->validate('BASE * 0.4', ['BASE', 'GROSS']));
        $this->assertNotEmpty($engine->validate('UNKNOWN * 2', ['BASE']));
        $this->assertNotEmpty($engine->validate('BASE / 0', ['BASE']));
        $this->assertNotEmpty($engine->validate('system("x")', ['BASE']));
        $result = $engine->evaluate('BASE / WORKING_DAYS * PAYABLE_DAYS', ['BASE' => '35000', 'WORKING_DAYS' => '30', 'PAYABLE_DAYS' => '20', 'IGNORED' => '1'], 2);
        $this->assertSame('23333.33', $result->value);
        $this->assertSame(['BASE' => '35000', 'WORKING_DAYS' => '30', 'PAYABLE_DAYS' => '20'], $result->usedVariables);
        $this->assertSame('23333.33', $result->trace()['value']);
    }

    public function test_registries_expose_the_foundation_providers_and_reject_unknown_handlers(): void
    {
        $inputs = app(PayrollInputRegistry::class);
        $this->assertSame(['attendance', 'leave'], $inputs->sources());
        $this->assertInstanceOf(AttendancePayrollInputProvider::class, $inputs->provider('attendance'));
        $this->assertTrue(app(PaymentFormatRegistry::class)->has('generic_csv'));
        $this->assertSame(['declarative'], app(StatutoryPackRegistry::class)->keys(), 'only the generic declarative interpreter is registered; no country pack is hard-coded');
        $this->assertNotContains('hardcoded_country_mapper', app(AccountingMapperRegistry::class)->keys());
        $this->expectException(\InvalidArgumentException::class);
        app(StatutoryPackRegistry::class)->pack('nowhere');
    }

    public function test_leave_provider_reads_the_leave_payroll_contract_with_no_money(): void
    {
        $this->hire('Asha Rao');
        $batch = app(PayrollInputRegistry::class)->provider('leave')->collect(new PayrollInputQuery('2026-09-01', '2026-09-30', $this->admin));
        $this->assertSame('leave', $batch->source);
        $this->assertSame([], $batch->lines);
        $this->assertFalse($batch->complete === false && $batch->warnings === [], 'a non-empty tenant without a locked leave period must warn');
    }

    public function test_attendance_rows_are_normalized_into_decimal_string_inputs(): void
    {
        $query = new PayrollInputQuery('2026-09-01', '2026-09-30', $this->admin, 'IN_MONTHLY');
        $row = ['employee_uuid' => 'e-1', 'employee_number' => 'E1', 'payable_days' => 28.5, 'present_days' => 20, 'absent_days' => 1.5, 'holiday_days' => 2, 'weekly_off_days' => 6,
            'approved_overtime_minutes' => 125, 'confidence' => ['code' => 'CONFIRMED'], 'complete' => true, 'includes_open_days' => false, 'excluded_open_days' => 0, 'unprocessed_days' => 0];
        $lines = AttendancePayrollInputProvider::linesFor($row, $query);
        $byKind = [];
        foreach ($lines as $l) {
            $byKind[$l->kind] = $l;
        }
        $this->assertSame('28.50', $byKind['payable_days']->quantity);
        $this->assertSame('125', $byKind['approved_overtime_minutes']->quantity);
        $this->assertSame('overtime', $byKind['approved_overtime_minutes']->source);
        $this->assertTrue($byKind['payable_days']->isFinal);
        $this->assertSame('attendance:IN_MONTHLY:2026-09-01:2026-09-30', $byKind['payable_days']->reference);
        $this->assertSame($byKind['payable_days']->dedupeKey('2026-09'), $byKind['payable_days']->dedupeKey('2026-09'));
        $this->assertNotSame($byKind['payable_days']->dedupeKey('2026-09'), $byKind['present_days']->dedupeKey('2026-09'));
        $open = AttendancePayrollInputProvider::linesFor(['complete' => false] + $row, $query);
        $this->assertFalse($open[0]->isFinal, 'open data is flagged non-final');
    }

    public function test_generic_payment_format_validates_and_generates_a_safe_csv(): void
    {
        $format = app(PaymentFormatRegistry::class)->format('generic_csv');
        $this->assertInstanceOf(GenericCsvPaymentFormat::class, $format);
        $batch = new PaymentBatchData('b-uuid-0001', 'r-uuid', 'INR', '2026-10-01', 1, [
            ['employee_uuid' => 'e1', 'employee_number' => 'E1', 'name' => '=HYPERLINK("x")', 'amount' => '62899.50', 'reference' => 'PAY-1', 'beneficiary' => ['bank_name' => 'B', 'account_number' => '123456']],
            ['employee_uuid' => 'e2', 'employee_number' => 'E2', 'name' => 'No Bank', 'amount' => '100.00', 'reference' => 'PAY-2', 'beneficiary' => []],
        ]);
        $this->assertSame(['Employee E2 has no bank account number or IBAN.'], $format->validate($batch));
        $good = new PaymentBatchData('b-uuid-0001', 'r-uuid', 'INR', '2026-10-01', 1, [$batch->instructions[0], ['amount' => '37.10'] + $batch->instructions[0]]);
        $file = $format->generate($good);
        $this->assertSame('62936.60', $good->total());
        $this->assertSame(2, $file->rows);
        $this->assertStringContainsString("'=HYPERLINK", $file->contents);
        $this->assertSame(hash('sha256', $file->contents), $file->checksum);
    }

    public function test_country_pack_versions_are_immutable_once_published(): void
    {
        $pack = CountryPack::create(['code' => 'XX-PAYROLL', 'country' => 'GB', 'name' => 'Test pack', 'handler' => 'declarative']);
        $version = CountryPackVersion::create(['pack_id' => $pack->id, 'version' => 1, 'status' => 'draft', 'effective_from' => '2026-04-06', 'calculation_rules' => ['rate' => '0.10']]);
        $version->update(['calculation_rules' => ['rate' => '0.12']]); // drafts are editable
        $version->update(['status' => 'published', 'published_at' => now(), 'published_by' => $this->admin->id]);
        try {
            $version->fresh()->update(['calculation_rules' => ['rate' => '0.99']]);
            $this->fail('published pack versions must be immutable');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $version->fresh()->update(['status' => 'retired', 'effective_to' => '2027-04-05']); // retirement bookkeeping is allowed
        $this->assertSame('retired', $version->fresh()->status);
        $this->expectException(LogicException::class);
        $version->fresh()->delete();
    }

    public function test_period_transitions_are_a_controlled_audited_state_machine(): void
    {
        $this->assertTrue(PayrollStates::canTransition(PayrollStates::PERIOD_TRANSITIONS, 'upcoming', 'input_open'));
        $this->assertFalse(PayrollStates::canTransition(PayrollStates::PERIOD_TRANSITIONS, 'upcoming', 'locked'));
        $this->assertFalse(PayrollStates::canTransition(PayrollStates::PERIOD_TRANSITIONS, 'closed', 'input_open'), 'closed periods only reopen');
        PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, 'approval', 'approved');
        try {
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, 'review', 'locked');
            $this->fail('skipping approval must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not allowed', $e->errors()['state'][0]);
        }
        $this->assertSame(array_keys(PayrollStates::PERIOD_TRANSITIONS), PayrollStates::PERIOD);

        $group = $this->payrollGroup();
        $period = $this->payrollPeriod($group);
        $row = PayrollPeriodTransition::create(['period_id' => $period->id, 'from_state' => 'upcoming', 'to_state' => 'input_open', 'actor_id' => $this->admin->id, 'reason' => 'Open inputs']);
        $this->expectException(LogicException::class);
        $row->update(['reason' => 'rewritten']);
    }

    public function test_module_routes_are_gated_and_skeleton_files_load(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/api/v1/payroll/groups')->assertOk();
        $this->getJson('/api/v1/payroll/readiness')->assertOk();
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member)->getJson('/api/v1/payroll/groups')->assertForbidden();
        $this->getJson('/api/v1/payroll/compensations')->assertForbidden();
    }
}
