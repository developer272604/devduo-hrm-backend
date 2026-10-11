<?php

namespace Tests\Feature\People;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Operations\ImportProcessor;
use App\Domain\People\Services\ReportingService;
use App\Domain\People\Services\StatusService;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OperationsFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use OperationsFixtures, PeopleFixtures, RefreshDatabase;

    private const HEAD = ['Emp No', 'First Name', 'Last Name', 'Work Email', 'Department', 'Location', 'Joining Date', 'Manager'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->opsSetUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        $this->opsTearDown();
        parent::tearDown();
    }

    private function importUuid(array $rows, array $options = []): string
    {
        return $this->upload($rows, $options)->assertCreated()->json('uuid');
    }

    private function runImport(string $uuid, array $commit = []): void
    {
        $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk();
        $this->postJson("/api/v1/people/imports/$uuid/commit", $commit)->assertStatus(202);
    }

    public function test_template_lists_supported_and_custom_field_columns(): void
    {
        DB::table('custom_field_definitions')->insert(['tenant_id' => $this->admin->tenant_id, 'entity' => 'employee', 'key' => 'shirt_size', 'label' => 'Shirt size', 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->get('/api/v1/people/imports/template')->assertOk();
        $csv = file_get_contents($res->baseResponse->getFile()->getPathname());
        $header = str_getcsv(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))[0], ',', '"', '');
        $this->assertContains('first_name', $header);
        $this->assertContains('manager_employee_number', $header);
        $this->assertContains('cf:shirt_size', $header);
        $this->get('/api/v1/people/imports/template?format=xlsx')->assertOk();
    }

    public function test_upload_detects_columns_and_suggests_mapping(): void
    {
        $res = $this->upload([self::HEAD, ['E1', 'Asha', 'Rao', 'asha@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']])->assertCreated();
        $res->assertJsonPath('total_rows', 1)->assertJsonPath('mapping.first_name', 'First Name')->assertJsonPath('mapping.joined_on', 'Joining Date')->assertJsonPath('mapping.employee_number', 'Emp No')
            ->assertJsonPath('mapping.manager_employee_number', 'Manager')->assertJsonPath('status', 'uploaded');
        $this->assertContains('Department', array_column($res->json('columns'), 'header'));
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('action', 'import_uploaded')->exists());
        $this->assertSame(0, DB::table('employees')->count(), 'upload writes nothing to employee tables');
    }

    public function test_happy_path_creates_people_assignments_and_managers_in_dependency_order(): void
    {
        // the report (E2) is listed BEFORE its manager (E1)
        $uuid = $this->importUuid([self::HEAD,
            ['E2', 'Ben', 'Ford', 'ben@alpha.test', 'Sales', 'London', '2026-01-05', 'E1'],
            ['E1', 'Asha', 'Rao', 'asha@alpha.test', 'ENG', 'chennai', '2026-01-05', ''],
            ['E3', 'Cy', 'Lee', 'cy@alpha.test', 'Engineering', 'CHN', '2026-02-01', 'E2']]);
        $validation = $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk()->assertJsonPath('counts.valid', 3)->assertJsonPath('counts.error', 0);
        $this->assertSame(0, DB::table('employees')->count(), 'validation is a dry run');
        $this->postJson("/api/v1/people/imports/$uuid/commit")->assertStatus(202);

        $this->context($this->admin->tenant_id);
        $import = EmployeeImport::where('uuid', $uuid)->first();
        $this->assertSame('completed', $import->status);
        $this->assertSame(3, $import->imported_rows);
        $e1 = Employee::where('employee_number', 'E1')->first();
        $e2 = Employee::where('employee_number', 'E2')->first();
        $e3 = Employee::where('employee_number', 'E3')->first();
        $this->assertSame($this->org['department'][0], $e1->assignmentOn('2026-10-07')->department_id);
        $this->assertSame($this->org['department'][1], $e2->assignmentOn('2026-10-07')->department_id);
        $this->assertSame('GB', $e2->country);
        $this->assertSame('Asha Rao', $e1->person->display_name);
        $reporting = app(ReportingService::class);
        $this->assertSame($e1->id, $reporting->getManager($e2)->id);
        $this->assertSame($e2->id, $reporting->getManager($e3)->id);
        $this->assertSame('active', $e3->status);
        $this->assertSame('import', Assignment::where('employee_id', $e1->id)->first()->source);
        $this->assertTrue(AuditLog::where('action', 'import_committed')->exists() && AuditLog::where('action', 'import_finished')->exists());
        $this->getJson('/api/v1/people/imports')->assertOk()->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.status', 'completed');
        $this->getJson("/api/v1/people/imports/$uuid")->assertOk()->assertJsonPath('counts.imported', 3);
    }

    public function test_unknown_references_get_friendly_messages_and_good_rows_still_import(): void
    {
        $uuid = $this->importUuid([self::HEAD,
            ['E1', 'Asha', 'Rao', 'asha@alpha.test', 'Salse', 'Chennai', '2026-01-05', ''],
            ['E2', 'Ben', 'Ford', 'ben@alpha.test', 'Sales', 'Chennai', '2026-01-05', 'NOPE'],
            ['E3', 'Cy', 'Lee', 'cy@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $res = $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk()->assertJsonPath('counts.error', 2)->assertJsonPath('counts.valid', 1);
        $messages = collect($res->json('problem_rows'))->flatMap(fn ($r) => array_column($r['errors'], 'message'))->all();
        $this->assertContains("Department 'Salse' was not found. Did you mean 'Sales'?", $messages);
        $this->assertContains("Manager 'NOPE' was not found in the system or in this file.", $messages);

        $this->postJson("/api/v1/people/imports/$uuid/commit")->assertStatus(422)->assertJsonFragment(['message' => '2 row(s) have errors and will not be imported. Fix them, or confirm to import the valid rows only (accept_errors).']);
        $this->postJson("/api/v1/people/imports/$uuid/commit", ['accept_errors' => true])->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame(['E3'], Employee::pluck('employee_number')->all(), 'only the valid row was inserted');
        $this->assertSame('partial', EmployeeImport::where('uuid', $uuid)->value('status'));
        $this->getJson("/api/v1/people/imports/$uuid/rows?status=error")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_errors_csv_and_retry_by_row_edit_and_by_corrected_file(): void
    {
        $uuid = $this->importUuid([self::HEAD,
            ['E1', 'Asha', 'Rao', 'asha@alpha.test', 'Salse', 'Chennai', '2026-01-05', ''],
            ['E2', 'Ben', 'Ford', 'ben@alpha.test', 'Sales', 'Atlantis', '2026-01-05', ''],
            ['E3', 'Cy', 'Lee', 'cy@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $this->runImport($uuid, ['accept_errors' => true]);

        $csv = $this->get("/api/v1/people/imports/$uuid/errors.csv")->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", ltrim($content, "\xEF\xBB\xBF"))));
        $this->assertSame(['row_number', ...self::HEAD, 'error_reason'], array_values($lines[0]));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString("Department 'Salse'", end($lines[1]));

        // fix row 2 (E1) by editing it, row 3 (E2) by re-uploading the corrected file
        $this->patchJson("/api/v1/people/imports/$uuid/rows/2", ['values' => ['Department' => 'Sales']])->assertOk()->assertJsonPath('status', 'pending');
        $fixed = $this->csvFile([['row_number', ...self::HEAD, 'error_reason'], [3, 'E2', 'Ben', 'Ford', 'ben@alpha.test', 'Sales', 'London', '2026-01-05', '', 'x']], 'errors.csv');
        $this->withHeader('Accept', 'application/json')->post("/api/v1/people/imports/$uuid/retry", ['file' => $fixed])->assertStatus(202)->assertJsonPath('corrections.updated', 1);

        $this->context($this->admin->tenant_id);
        $this->assertEqualsCanonicalizing(['E1', 'E2', 'E3'], Employee::pluck('employee_number')->all());
        $this->assertSame('completed', EmployeeImport::where('uuid', $uuid)->value('status'));
    }

    public function test_duplicates_in_file_are_errors_and_existing_matches_need_a_decision(): void
    {
        $this->hire('Maya Chen', ['work_email' => 'maya@alpha.test', 'dob' => '1990-01-01']);
        $rows = [self::HEAD,
            ['E1', 'Asha', 'Rao', 'asha@alpha.test', 'Sales', 'Chennai', '2026-01-05', ''],
            ['E1', 'Asha', 'Two', 'other@alpha.test', 'Sales', 'Chennai', '2026-01-05', ''],
            ['E3', 'Cy', 'Lee', 'asha@alpha.test', 'Sales', 'Chennai', '2026-01-05', ''],
            ['E4', 'Maya', 'Chen', 'maya@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']];
        $uuid = $this->importUuid($rows);
        $res = $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk()->assertJsonPath('counts.error', 2)->assertJsonPath('counts.warning', 1)->assertJsonPath('requires_duplicate_decision', true);
        $text = json_encode($res->json('problem_rows'));
        $this->assertStringContainsString('also used on row 2', $text);
        $this->assertStringContainsString('Possible duplicate of Maya Chen', $text);

        $this->postJson("/api/v1/people/imports/$uuid/commit", ['accept_errors' => true])->assertStatus(422)->assertJsonFragment(['message' => '1 row(s) look like people who already exist. Choose to skip them (skip_duplicates) or import them anyway (allow_duplicates) in the options.']);
        $this->putJson("/api/v1/people/imports/$uuid/mapping", ['mapping' => $this->importMapping($uuid), 'options' => ['skip_duplicates' => true]])->assertOk();
        $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk();
        $this->postJson("/api/v1/people/imports/$uuid/commit", ['accept_errors' => true])->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame(['E1'], Employee::whereIn('employee_number', ['E1', 'E3', 'E4'])->pluck('employee_number')->all());
        $import = EmployeeImport::where('uuid', $uuid)->first();
        $this->assertSame(1, $import->skipped_rows);
    }

    private function importMapping(string $uuid): array
    {
        return $this->getJson("/api/v1/people/imports/$uuid")->json('mapping');
    }

    public function test_allow_duplicates_imports_the_probable_duplicate(): void
    {
        $this->hire('Maya Chen', ['work_email' => 'maya@alpha.test']);
        $uuid = $this->importUuid([self::HEAD, ['E4', 'Maya', 'Chen', 'maya@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']], ['allow_duplicates' => true]);
        $this->runImport($uuid);
        $this->context($this->admin->tenant_id);
        $this->assertTrue(Employee::where('employee_number', 'E4')->exists());
    }

    public function test_dates_follow_the_chosen_format_and_never_guess(): void
    {
        $rows = [self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '05/03/2026', '']];
        $uuid = $this->importUuid($rows);
        $res = $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk()->assertJsonPath('counts.error', 1);
        $this->assertStringContainsString('choose the date format', $res->json('problem_rows.0.errors.0.message'));

        $this->putJson("/api/v1/people/imports/$uuid/mapping", ['mapping' => $this->importMapping($uuid), 'options' => ['date_format' => 'd/m/Y']])->assertOk();
        $this->postJson("/api/v1/people/imports/$uuid/validate")->assertOk()->assertJsonPath('counts.valid', 1);
        $this->postJson("/api/v1/people/imports/$uuid/commit")->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame('2026-03-05', Employee::where('employee_number', 'E1')->first()->joined_on->toDateString());

        $other = $this->importUuid([self::HEAD, ['E2', 'Ben', 'Ford', 'b@alpha.test', 'Sales', 'Chennai', '13/25/2026', '']], ['date_format' => 'm/d/Y']);
        $this->postJson("/api/v1/people/imports/$other/validate")->assertOk()->assertJsonPath('counts.error', 1);
    }

    public function test_recommit_is_idempotent(): void
    {
        $uuid = $this->importUuid([self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $this->runImport($uuid);
        $this->postJson("/api/v1/people/imports/$uuid/commit")->assertStatus(409);
        $this->context($this->admin->tenant_id);
        $import = EmployeeImport::where('uuid', $uuid)->first();
        $import->update(['status' => 'processing']);
        app(ImportProcessor::class)->run($import, $this->admin);   // a duplicate job run
        $this->assertSame(1, Employee::count());
        $this->assertSame('completed', $import->refresh()->status);
    }

    public function test_rollback_removes_untouched_employees_and_frees_their_numbers(): void
    {
        $uuid = $this->importUuid([self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', ''], ['E2', 'Ben', 'Ford', 'b@alpha.test', 'Sales', 'Chennai', '2026-01-05', 'E1']]);
        $this->runImport($uuid);
        $this->postJson("/api/v1/people/imports/$uuid/rollback")->assertOk()->assertJsonPath('rolled_back', 2)->assertJsonPath('import.status', 'rolled_back');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, Employee::count());
        $this->assertSame(2, Employee::withTrashed()->count());
        $this->assertTrue(AuditLog::where('action', 'import_rolled_back')->exists());

        $again = $this->importUuid([self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $this->runImport($again);
        $this->context($this->admin->tenant_id);
        $this->assertSame('E1', Employee::first()->employee_number);
    }

    public function test_rollback_is_refused_when_an_imported_employee_has_activity(): void
    {
        $uuid = $this->importUuid([self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', ''], ['E2', 'Ben', 'Ford', 'b@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $this->runImport($uuid);
        $this->context($this->admin->tenant_id);
        app(StatusService::class)->transition(Employee::where('employee_number', 'E2')->first(), 'inactive');
        $res = $this->postJson("/api/v1/people/imports/$uuid/rollback")->assertStatus(409)->assertJsonPath('code', 'rollback_blocked');
        $this->assertSame('E2', $res->json('blocked.0.employee_number'));
        $this->assertContains('its status changed', $res->json('blocked.0.reasons'));
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, Employee::count(), 'nothing is rolled back');
    }

    public function test_custom_field_columns_are_imported(): void
    {
        DB::table('custom_field_definitions')->insert(['tenant_id' => $this->admin->tenant_id, 'entity' => 'employee', 'key' => 'shirt_size', 'label' => 'Shirt size', 'type' => 'text', 'active' => true,
            'classification' => 'public_internal', 'hr_editable' => true, 'created_at' => now(), 'updated_at' => now()]);
        $uuid = $this->importUuid([[...self::HEAD, 'cf:shirt_size'], ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', '', 'M']]);
        $this->runImport($uuid);
        $this->assertSame('"M"', DB::table('custom_field_values')->value('value'));
    }

    public function test_row_and_size_limits(): void
    {
        config(['people_operations.import.max_rows' => 2]);
        $this->upload([self::HEAD, ['E1', 'A', 'B', 'a@x.test', 'Sales', 'Chennai', '2026-01-05', ''], ['E2', 'A', 'B', 'b@x.test', 'Sales', 'Chennai', '2026-01-05', ''], ['E3', 'A', 'B', 'c@x.test', 'Sales', 'Chennai', '2026-01-05', '']])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_rows');
        $this->assertSame(0, EmployeeImport::count());
        config(['people_operations.import.max_rows' => 20000]);
        $big = \Illuminate\Http\UploadedFile::fake()->create('big.csv', 11 * 1024);
        $this->withHeader('Accept', 'application/json')->post('/api/v1/people/imports', ['file' => $big])->assertStatus(422);
        $this->upload([self::HEAD])->assertStatus(422);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $uuid = $this->importUuid([self::HEAD, ['E1', 'Asha', 'Rao', 'a@alpha.test', 'Sales', 'Chennai', '2026-01-05', '']]);
        $this->context($this->admin->tenant_id);
        $viewer = $this->roleUser(['people.view']);
        $this->signIn($viewer);
        $this->getJson('/api/v1/people/imports')->assertForbidden();
        $this->upload([self::HEAD])->assertForbidden();

        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson("/api/v1/people/imports/$uuid")->assertNotFound();
        $this->postJson("/api/v1/people/imports/$uuid/validate")->assertNotFound();
        $this->postJson("/api/v1/people/imports/$uuid/commit")->assertNotFound();
        $this->getJson("/api/v1/people/imports/$uuid/errors.csv")->assertNotFound();
        $this->getJson('/api/v1/people/imports')->assertOk()->assertJsonCount(0, 'data');
    }
}
