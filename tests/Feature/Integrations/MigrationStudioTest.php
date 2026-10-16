<?php

namespace Tests\Feature\Integrations;

use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class MigrationStudioTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const API = '/api/v1/integrations/migration-projects';

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->emp = $this->hire('Arun Kumar', ['role' => 'Team member']);
        $this->signIn($this->admin);
    }

    private function employeeImport(string $status = 'completed', int $total = 10, int $ok = 10, int $err = 0): string
    {
        $uuid = (string) Str::uuid();
        DB::table('employee_imports')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => $uuid, 'user_id' => $this->admin->id, 'filename' => 'zoho.csv', 'status' => $status,
            'total_rows' => $total, 'imported_rows' => $ok, 'error_rows' => $err, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    private function leaveImport(string $kind, string $status = 'completed', int $ok = 5): string
    {
        $uuid = (string) Str::uuid();
        DB::table('leave_imports')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => $uuid, 'user_id' => $this->admin->id, 'kind' => $kind, 'filename' => 'leave.csv', 'status' => $status,
            'total_rows' => $ok, 'imported_rows' => $ok, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    private function project(): string
    {
        return $this->postJson(self::API, ['name' => 'Move from Legacy HR', 'source_system' => 'Legacy HR'])->assertCreated()->json('uuid');
    }

    private function act(string $uuid, string $action, array $body = [])
    {
        return $this->postJson(self::API."/$uuid/$action", $body);
    }

    private function stage(array $project, string $code): array
    {
        return collect($project['stages'])->firstWhere('code', $code);
    }

    public function test_project_starts_with_ordered_stages_and_dependencies(): void
    {
        $res = $this->postJson(self::API, ['name' => 'Move', 'source_system' => 'X'])->assertCreated();
        $this->assertSame(['employees', 'leave_opening_balances', 'leave_history', 'compensation'], array_column($res->json('stages'), 'code'));
        $this->assertSame('planning', $res->json('status'));
        $this->assertFalse($res->json('can_go_live'));
        $this->assertSame(['Employees and organization'], $this->stage($res->json(), 'compensation')['waiting_for']);
    }

    public function test_dependent_stage_cannot_link_before_its_prerequisite(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'link', ['stage' => 'compensation', 'import_uuid' => (string) Str::uuid()])->assertStatus(422);
        $this->act($uuid, 'link', ['stage' => 'leave_opening_balances', 'import_uuid' => $this->leaveImport('opening_balance')])->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_link_validates_kind_and_exclusivity(): void
    {
        $uuid = $this->project();
        $emp = $this->employeeImport();
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('import_uuid');
        $res = $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $emp])->assertOk();
        $this->assertSame('imported', $this->stage($res->json(), 'employees')['status']);
        $this->assertSame('in_progress', $res->json('status'));
        // A leave import of the wrong kind is not accepted for the historical stage.
        $this->act($uuid, 'link', ['stage' => 'leave_opening_balances', 'import_uuid' => $this->leaveImport('historical_requests')])->assertStatus(422);
        // The same import cannot serve two projects.
        $other = $this->project();
        $this->act($other, 'link', ['stage' => 'employees', 'import_uuid' => $emp])->assertStatus(422)->assertJsonValidationErrors('import_uuid');
    }

    public function test_import_still_running_is_linked_not_imported(): void
    {
        $uuid = $this->project();
        $res = $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport('processing', 10, 4)])->assertOk();
        $this->assertSame('linked', $this->stage($res->json(), 'employees')['status']);
        $this->act($uuid, 'verify', ['stage' => 'employees'])->assertStatus(422);
    }

    public function test_verify_needs_a_reconciliation_and_matching_numbers_or_a_reasoned_override(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport('completed', 10, 10)])->assertOk();
        $this->act($uuid, 'verify', ['stage' => 'employees'])->assertStatus(422)->assertJsonValidationErrors('reconciliation');

        $res = $this->act($uuid, 'reconcile', ['stage' => 'employees', 'metric' => 'rows_imported', 'source_value' => 12])->assertOk();
        $rec = $this->stage($res->json(), 'employees')['reconciliations'][0];
        $this->assertSame('variance', $rec['status']);
        $this->assertEquals(-2.0, $rec['variance']);
        $this->act($uuid, 'verify', ['stage' => 'employees'])->assertStatus(422)->assertJsonValidationErrors('override_reason');
        $ok = $this->act($uuid, 'verify', ['stage' => 'employees', 'override_reason' => 'Two rows were duplicates in the old system'])->assertOk();
        $this->assertSame('verified', $this->stage($ok->json(), 'employees')['status']);

        // Matching figures verify without an override.
        $uuid2 = $this->project();
        $this->act($uuid2, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport('completed', 8, 8)])->assertOk();
        $this->act($uuid2, 'reconcile', ['stage' => 'employees', 'metric' => 'rows_imported', 'source_value' => 8])->assertOk();
        $this->act($uuid2, 'verify', ['stage' => 'employees'])->assertOk();
    }

    public function test_headcount_is_measured_from_live_data_not_typed_in(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport()])->assertOk();
        $total = DB::table('employees')->where('tenant_id', $this->admin->tenant_id)->whereNull('deleted_at')->count();
        $res = $this->act($uuid, 'reconcile', ['stage' => 'employees', 'metric' => 'headcount_total', 'source_value' => $total])->assertOk();
        $rec = $this->stage($res->json(), 'employees')['reconciliations'][0];
        $this->assertSame('matched', $rec['status']);
        $this->assertEquals($total, $rec['imported_value']);
        $this->act($uuid, 'reconcile', ['stage' => 'leave_history', 'metric' => 'headcount_total', 'source_value' => 1])->assertStatus(422);
    }

    public function test_tolerance_allows_small_differences(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport('completed', 10, 10)])->assertOk();
        $res = $this->act($uuid, 'reconcile', ['stage' => 'employees', 'metric' => 'rows_imported', 'source_value' => 11, 'tolerance' => 1])->assertOk();
        $this->assertSame('matched', $this->stage($res->json(), 'employees')['reconciliations'][0]['status']);
    }

    public function test_dependent_stage_cannot_be_verified_before_prerequisite_is_verified(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport()])->assertOk();
        $this->act($uuid, 'link', ['stage' => 'leave_opening_balances', 'import_uuid' => $this->leaveImport('opening_balance')])->assertOk();
        $this->act($uuid, 'reconcile', ['stage' => 'leave_opening_balances', 'metric' => 'rows_imported', 'source_value' => 5])->assertOk();
        $this->act($uuid, 'verify', ['stage' => 'leave_opening_balances'])->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_rolling_back_the_import_invalidates_a_verified_stage(): void
    {
        $uuid = $this->project();
        $import = $this->employeeImport('completed', 10, 10);
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $import])->assertOk();
        $this->act($uuid, 'reconcile', ['stage' => 'employees', 'metric' => 'rows_imported', 'source_value' => 10])->assertOk();
        $this->act($uuid, 'verify', ['stage' => 'employees'])->assertOk();

        DB::table('employee_imports')->where('uuid', $import)->update(['status' => 'rolled_back', 'imported_rows' => 0]);
        $res = $this->getJson(self::API."/$uuid")->assertOk();
        $this->assertSame('linked', $this->stage($res->json(), 'employees')['status']);
        $this->assertContains('invalidated', array_column($res->json('history'), 'type'));
    }

    public function test_skipping_needs_a_reason_and_unblocks_dependents(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'skip', ['stage' => 'employees'])->assertStatus(422);
        $this->act($uuid, 'skip', ['stage' => 'employees', 'reason' => 'Employees were created in Devduo directly'])->assertOk();
        $this->act($uuid, 'link', ['stage' => 'compensation', 'import_uuid' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('import_uuid'); // passes the dependency check, fails on the missing import
    }

    public function test_go_live_is_blocked_until_every_stage_is_verified_or_skipped(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'go-live')->assertStatus(422);
        $this->act($uuid, 'link', ['stage' => 'employees', 'import_uuid' => $this->employeeImport()])->assertOk();
        $this->act($uuid, 'reconcile', ['stage' => 'employees', 'metric' => 'rows_imported', 'source_value' => 10])->assertOk();
        $this->act($uuid, 'verify', ['stage' => 'employees'])->assertOk();
        $this->act($uuid, 'go-live')->assertStatus(422); // other stages still pending
        foreach (['leave_opening_balances', 'leave_history', 'compensation'] as $code) {
            $this->act($uuid, 'skip', ['stage' => $code, 'reason' => 'Not migrating this data'])->assertOk();
        }
        $res = $this->getJson(self::API."/$uuid")->assertOk();
        $this->assertSame('ready_for_cutover', $res->json('status'));
        $this->assertTrue($res->json('can_go_live'));
        $this->act($uuid, 'go-live')->assertOk()->assertJsonPath('status', 'live');
        $this->act($uuid, 'skip', ['stage' => 'compensation', 'reason' => 'x'])->assertStatus(422); // live projects are frozen
    }

    public function test_reopen_and_cancel_require_reasons(): void
    {
        $uuid = $this->project();
        $this->act($uuid, 'skip', ['stage' => 'compensation', 'reason' => 'later'])->assertOk();
        $this->act($uuid, 'reopen', ['stage' => 'compensation'])->assertStatus(422);
        $this->act($uuid, 'reopen', ['stage' => 'compensation', 'reason' => 'We do need it'])->assertOk();
        $this->act($uuid, 'cancel')->assertStatus(422);
        $this->act($uuid, 'cancel', ['reason' => 'Vendor changed plans'])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $uuid = $this->project();
        $this->signIn(User::findOrFail(DB::table('people')->where('id', $this->emp->person_id)->value('user_id')));
        $this->getJson(self::API)->assertForbidden();
        $this->postJson(self::API, ['name' => 'Nope'])->assertForbidden();

        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson(self::API)->assertOk()->assertJsonPath('items', []);
        $this->getJson(self::API."/$uuid")->assertNotFound();
    }
}
