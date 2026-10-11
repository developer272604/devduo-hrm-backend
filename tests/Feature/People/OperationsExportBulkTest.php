<?php

namespace Tests\Feature\People;

use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\BulkRun;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeExport;
use App\Domain\People\Models\Person;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\BulkActions;
use App\Models\AuditLog;
use App\Support\Spreadsheet\TabularReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\OperationsFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class OperationsExportBulkTest extends TestCase
{
    use OperationsFixtures, PeopleFixtures, RefreshDatabase;

    private Employee $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->opsSetUp();
        $this->bootTenant();
        $this->boss = $this->hire('Mia Boss');
    }

    protected function tearDown(): void
    {
        $this->opsTearDown();
        parent::tearDown();
    }

    private function exportRows(string $uuid): array
    {
        $this->context($this->admin->tenant_id);
        $export = EmployeeExport::where('uuid', $uuid)->firstOrFail();
        $path = Storage::disk('hrm_exports')->path($export->path);

        return [$export, array_values(iterator_to_array(TabularReader::open($path, 'x.'.$export->format, ['normalize_headers' => false])->rows()))];
    }

    private function scopedManager(array $extra = []): array
    {
        $user = $this->roleUser(array_merge(['people.view', 'people.manage', 'people.bulk', 'people.export', 'people.personal.view'], $extra), 'direct_reports');
        $emp = $this->hire('Sam Lead');
        Person::query()->whereKey($emp->person_id)->update(['user_id' => $user->id]);

        return [$user, $emp];
    }

    // ---- export -------------------------------------------------------------------------------

    public function test_column_catalogue_follows_permissions_and_never_offers_highly_sensitive(): void
    {
        $plain = $this->roleUser(['people.view', 'people.export', 'people.personal.view']);
        $this->signIn($plain);
        $keys = array_column($this->getJson('/api/v1/people/exports/columns')->assertOk()->json('data'), 'key');
        $this->assertContains('date_of_birth', $keys);
        $this->assertNotContains('grade', $keys, 'hr-confidential needs export_sensitive');
        $this->assertNotContains('bank', $keys);

        $this->context($this->admin->tenant_id);
        $hr = $this->roleUser(['people.view', 'people.export', 'people.export_sensitive', 'people.personal.view', 'people.confidential.view', 'people.sensitive.reveal']);
        $this->signIn($hr);
        $res = $this->getJson('/api/v1/people/exports/columns')->assertOk();
        $this->assertContains('grade', array_column($res->json('data'), 'key'));
        $this->assertSame([], array_values(array_intersect(array_column($res->json('data'), 'key'), ['identity', 'identity_number', 'bank', 'salary', 'last_salary'])));

        $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'columns' => ['employee_number', 'bank']])->assertStatus(422)->assertJsonPath('errors.columns.0', 'Bank details can never be exported.');
        $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'columns' => ['nope']])->assertStatus(422);
        $this->signIn($plain);
        $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'columns' => ['grade']])->assertStatus(422)->assertJsonPath('errors.columns.0', 'Grade is confidential and needs the export-sensitive permission.');
        $this->signIn($this->roleUser(['people.view']));
        $this->getJson('/api/v1/people/exports/columns')->assertForbidden();
    }

    public function test_export_csv_and_xlsx_are_complete_formula_safe_and_audited(): void
    {
        $this->hire('=cmd Evil', ['work_email' => 'evil@alpha.test']);
        $this->signIn($this->admin);
        $res = $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'columns' => ['employee_number', 'display_name', 'department', 'manager', 'grade']])->assertStatus(202)->assertJsonPath('status', 'completed');
        [$export, $rows] = $this->exportRows($res->json('uuid'));
        $this->assertSame(2, $export->row_count);
        $this->assertTrue($export->includes_sensitive);
        $this->assertSame("'=cmd Evil", collect($rows)->pluck('Name')->first(fn ($n) => str_contains($n, 'cmd')));
        $this->assertContains('Engineering', array_column($rows, 'Department'));
        $this->context($this->admin->tenant_id);
        $audit = AuditLog::where('action', 'export_completed')->latest('id')->first();
        $this->assertContains('grade', $audit->context['columns'], 'sensitive exports record column names');
        $this->assertTrue(AuditLog::where('action', 'export_requested')->exists());

        $this->signIn($this->admin);
        $x = $this->postJson('/api/v1/people/exports', ['format' => 'xlsx'])->assertStatus(202)->json('uuid');
        [, $xrows] = $this->exportRows($x);
        $this->assertCount(2, $xrows);
        $this->signIn($this->admin);
        $this->getJson('/api/v1/people/exports')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/people/exports/$x")->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_export_honors_data_scope_and_selected_employees(): void
    {
        [$user, $lead] = $this->scopedManager();
        $mine = $this->hire('Ann One', ['manager' => $lead]);
        $this->hire('Zed Other');
        $this->signIn($user);
        $uuid = $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'columns' => ['employee_number', 'display_name']])->assertStatus(202)->json('uuid');
        [, $rows] = $this->exportRows($uuid);
        $this->assertEqualsCanonicalizing(['Sam Lead', 'Ann One'], array_column($rows, 'Name'));

        $this->signIn($user);
        $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'selected_uuids' => [Employee::where('employee_number', '!=', $mine->employee_number)->where('person_id', '!=', $lead->person_id)->first()->uuid]])->assertStatus(422);
        $this->context($this->admin->tenant_id);
        $this->signIn($user);
        $uuid = $this->postJson('/api/v1/people/exports', ['format' => 'csv', 'selected_uuids' => [$mine->uuid], 'columns' => ['display_name']])->assertStatus(202)->json('uuid');
        [, $rows] = $this->exportRows($uuid);
        $this->assertSame(['Ann One'], array_column($rows, 'Name'));
    }

    public function test_download_is_private_expires_and_is_purged(): void
    {
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/people/exports', ['format' => 'csv'])->assertStatus(202)->json('uuid');
        $this->get("/api/v1/people/exports/$uuid/download")->assertOk()->assertHeader('content-disposition');
        $other = $this->roleUser(['people.view', 'people.export']);
        $this->signIn($other);
        $this->getJson("/api/v1/people/exports/$uuid/download")->assertNotFound();

        $this->context($this->admin->tenant_id);
        $path = EmployeeExport::where('uuid', $uuid)->value('path');
        $this->travel(25)->hours();
        $this->signIn($this->admin);
        $this->getJson("/api/v1/people/exports/$uuid/download")->assertStatus(410);
        $this->artisan('people:purge-exports')->assertSuccessful();
        $this->assertFalse(Storage::disk('hrm_exports')->exists($path));
        $this->context($this->admin->tenant_id);
        $this->assertSame('expired', EmployeeExport::where('uuid', $uuid)->value('status'));
    }

    // ---- bulk ---------------------------------------------------------------------------------

    private function preview(string $action, array $params, array $uuids): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/people/bulk/preview', ['action' => $action, 'params' => $params, 'employee_uuids' => $uuids]);
    }

    public function test_actions_depend_on_permissions(): void
    {
        $this->signIn($this->admin);
        $keys = array_column($this->getJson('/api/v1/people/bulk/actions')->assertOk()->json('data'), 'key');
        foreach (['assign_department', 'assign_location', 'assign_manager', 'assign_employee_type', 'add_tag', 'remove_tag', 'activate', 'deactivate', 'export'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->signIn($this->roleUser(['people.view', 'people.bulk', 'people.manage']));
        $this->assertNotContains('export', array_column($this->getJson('/api/v1/people/bulk/actions')->json('data'), 'key'));
        $this->signIn($this->roleUser(['people.view', 'people.manage']));
        $this->getJson('/api/v1/people/bulk/actions')->assertForbidden();
    }

    public function test_preview_token_apply_with_effective_dated_changes(): void
    {
        $a = $this->hire('Ann One');
        $b = $this->hire('Bob Two', ['department' => 1]);
        $this->signIn($this->admin);
        $res = $this->preview('assign_department', ['department_id' => $this->org['department'][1], 'effective_from' => '2026-10-01', 'reason' => 'Reorg'], [$a->uuid, $b->uuid])->assertOk();
        $res->assertJsonPath('affected_count', 2)->assertJsonPath('counts.ok', 1)->assertJsonPath('counts.skipped', 1);
        $this->assertSame('Already assigned to Sales.', collect($res->json('rows'))->firstWhere('status', 'skipped')['reason']);
        $token = $res->json('confirmation_token');

        $apply = $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $token])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('applied', 1)->assertJsonPath('skipped', 1);
        $this->context($this->admin->tenant_id);
        $rows = Assignment::where('employee_id', $a->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-30', $rows[0]->effective_to->toDateString());
        $this->assertSame($this->org['department'][1], $rows[1]->department_id);
        $this->assertSame('bulk', $rows[1]->source);
        $this->assertTrue(AuditLog::where('action', 'bulk_finished')->exists());

        $this->signIn($this->admin);
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $token])->assertStatus(422);
    }

    public function test_token_cannot_be_tampered_borrowed_or_used_after_expiry(): void
    {
        $a = $this->hire('Ann One');
        $this->signIn($this->admin);
        $token = $this->preview('deactivate', [], [$a->uuid])->assertOk()->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => substr($token, 0, -3).'abc'])->assertStatus(422);
        [$payload, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(str_replace((string) $this->admin->id, '999', base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=').'.'.$sig;
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $forged])->assertStatus(422);

        $this->context($this->admin->tenant_id);
        $other = $this->roleUser(['people.view', 'people.manage', 'people.bulk']);
        $this->signIn($other);
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $token])->assertStatus(422);

        // changing the stored id set invalidates the token binding
        $this->context($this->admin->tenant_id);
        $run = BulkRun::first();
        $run->update(['employee_ids' => [$a->id, $this->boss->id]]);
        $this->signIn($this->admin);
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $token])->assertStatus(422);

        $this->context($this->admin->tenant_id);
        $run->update(['employee_ids' => [$a->id]]);
        $this->travel(16)->minutes();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $token])->assertStatus(422)->assertJsonFragment(['message' => 'This confirmation has expired. Preview the action again.']);
    }

    public function test_out_of_scope_employees_are_not_found_and_partial_failures_are_reported(): void
    {
        [$user, $lead] = $this->scopedManager();
        $mine = $this->hire('Ann One', ['manager' => $lead, 'joined_on' => '2026-10-05']);
        $early = $this->hire('Early Bird', ['manager' => $lead, 'joined_on' => '2026-01-05']);
        $outside = $this->hire('Zed Other');
        $this->signIn($user);
        $res = $this->preview('assign_location', ['location_id' => $this->org['location'][1], 'effective_from' => '2026-10-03'], [$mine->uuid, $early->uuid, $outside->uuid])->assertOk();
        $res->assertJsonPath('counts.error', 2)->assertJsonPath('counts.ok', 1);
        $reasons = collect($res->json('rows'))->pluck('reason', 'uuid');
        $this->assertSame('Employee not found.', $reasons[$outside->uuid]);
        $this->assertStringContainsString('before this employee joined', $reasons[$mine->uuid]);
        $this->assertSame(2, $res->json('affected_count') + 0);

        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $res->json('confirmation_token')])->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('applied', 1)->assertJsonPath('failed_count', 1);
        $this->context($this->admin->tenant_id);
        $this->assertSame($this->org['location'][1], $early->assignmentOn('2026-10-07')->location_id);
        $this->assertSame($this->org['location'][0], $outside->assignmentOn('2026-10-07')->location_id, 'never touched');
    }

    public function test_assign_manager_activate_and_deactivate(): void
    {
        $a = $this->hire('Ann One');
        $b = $this->hire('Bob Two', ['status' => 'preboarding']);
        $this->signIn($this->admin);
        $res = $this->preview('assign_manager', ['manager_uuid' => $a->uuid, 'effective_from' => '2026-10-01'], [$a->uuid, $b->uuid])->assertOk();
        $this->assertSame('An employee cannot report to themselves.', collect($res->json('rows'))->firstWhere('uuid', $a->uuid)['reason']);
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $res->json('confirmation_token')])->assertOk()->assertJsonPath('applied', 1)->assertJsonPath('failed_count', 1);

        $this->signIn($this->admin);
        $t = $this->preview('activate', [], [$b->uuid])->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $t])->assertOk()->assertJsonPath('applied', 1);
        $this->signIn($this->admin);
        $t = $this->preview('deactivate', ['reason' => 'Left'], [$b->uuid, $a->uuid])->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $t])->assertOk()->assertJsonPath('applied', 2);
        $this->context($this->admin->tenant_id);
        $this->assertSame('inactive', $b->refresh()->status);
        $this->signIn($this->admin);
        $this->preview('deactivate', [], [$b->uuid])->assertOk()->assertJsonPath('counts.skipped', 1);
    }

    public function test_tags_honor_required_permission(): void
    {
        $a = $this->hire('Ann One');
        $open = DB::table('tags')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Mentor', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $locked = DB::table('tags')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Watch', 'active' => true, 'required_permission' => 'people.notes.private', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->roleUser(['people.view', 'people.manage', 'people.bulk']);
        $this->signIn($user);
        $t = $this->preview('add_tag', ['tag_id' => $open], [$a->uuid])->assertOk()->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $t])->assertOk()->assertJsonPath('applied', 1);
        $this->preview('add_tag', ['tag_id' => $open], [$a->uuid])->assertOk()->assertJsonPath('counts.skipped', 1);
        $this->preview('add_tag', ['tag_id' => $locked], [$a->uuid])->assertOk()->assertJsonPath('counts.error', 1);
        $t = $this->preview('remove_tag', ['tag_id' => $open], [$a->uuid])->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $t])->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame(0, DB::table('employee_tags')->count());
    }

    public function test_large_runs_are_queued_and_export_action_creates_an_export(): void
    {
        config(['people_operations.bulk.queue_above' => 1]);
        $a = $this->hire('Ann One');
        $b = $this->hire('Bob Two');
        $this->signIn($this->admin);
        $res = $this->preview('deactivate', [], [$a->uuid, $b->uuid])->assertOk()->assertJsonPath('will_queue', true);
        $run = $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $res->json('confirmation_token')]);
        $uuid = $run->json('uuid');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/people/bulk/runs/$uuid")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('applied', 2);

        $res = $this->preview('export', ['format' => 'csv', 'columns' => ['display_name']], [$a->uuid, $b->uuid])->assertOk();
        $done = $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $res->json('confirmation_token')]);
        $exportUuid = $done->json('result.export_uuid');
        $this->assertNotEmpty($exportUuid);
        [, $rows] = $this->exportRows($exportUuid);
        $this->assertEqualsCanonicalizing(['Ann One', 'Bob Two'], array_column($rows, 'Name'));
        $this->signIn($this->admin);
        $this->preview('nonsense', [], [$a->uuid])->assertStatus(422);
        $this->preview('assign_department', [], [$a->uuid])->assertStatus(422);
    }

    public function test_other_modules_can_register_their_own_bulk_action(): void
    {
        app(BulkActions::class)->register(new class implements BulkAction
        {
            public function key(): string
            {
                return 'send_invitation';
            }

            public function permission(): string
            {
                return 'people.invite';
            }

            public function validate(array $params): array
            {
                return $params;
            }

            public function preview(\App\Models\User $user, Collection $employees, array $params): array
            {
                return $employees->mapWithKeys(fn ($e) => [$e->id => ['status' => 'ok']])->all();
            }

            public function apply(\App\Models\User $user, Employee $employee, array $params): void {}
        });
        $this->signIn($this->admin);
        $this->assertContains('send_invitation', array_column($this->getJson('/api/v1/people/bulk/actions')->json('data'), 'key'));
        $t = $this->preview('send_invitation', [], [$this->boss->uuid])->assertOk()->json('confirmation_token');
        $this->postJson('/api/v1/people/bulk/apply', ['confirmation_token' => $t])->assertOk()->assertJsonPath('applied', 1);
    }

    // ---- saved views ---------------------------------------------------------------------------

    public function test_saved_views_validation_and_sharing_rules(): void
    {
        $this->signIn($this->admin);
        $cols = $this->getJson('/api/v1/people/directory/columns')->assertOk()->json('data');
        $this->assertContains('department', array_column($cols, 'key'));

        $payload = ['name' => 'Engineers', 'filters' => ['department_id' => [$this->org['department'][0]], 'status' => ['active']], 'columns' => ['display_name', 'department'], 'sort' => ['key' => 'name', 'dir' => 'asc']];
        $uuid = $this->postJson('/api/v1/people/views', $payload)->assertCreated()->assertJsonPath('owned', true)->json('uuid');
        $this->postJson('/api/v1/people/views', $payload)->assertStatus(422)->assertJsonPath('errors.name.0', 'You already have a view with this name.');
        $this->postJson('/api/v1/people/views', ['name' => 'Bad', 'filters' => ['salary' => 1]])->assertStatus(422)->assertJsonValidationErrors('filters');
        $this->postJson('/api/v1/people/views', ['name' => 'Bad', 'columns' => ['bank']])->assertStatus(422)->assertJsonValidationErrors('columns');
        $this->postJson('/api/v1/people/views', ['name' => 'Bad', 'sort' => ['key' => 'salary']])->assertStatus(422);

        $this->putJson("/api/v1/people/views/$uuid", ['shared' => true])->assertOk()->assertJsonPath('shared', true);

        $this->context($this->admin->tenant_id);
        $viewer = $this->roleUser(['people.view']);
        $this->signIn($viewer);
        $this->getJson('/api/v1/people/views')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.read_only', true);
        $this->putJson("/api/v1/people/views/$uuid", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/v1/people/views/$uuid")->assertForbidden();
        $this->postJson('/api/v1/people/views', ['name' => 'Mine', 'shared' => true])->assertForbidden();
        $mine = $this->postJson('/api/v1/people/views', ['name' => 'Mine'])->assertCreated()->json('uuid');

        $this->signIn($this->admin);
        $this->getJson("/api/v1/people/views/$mine")->assertNotFound();
        $this->deleteJson("/api/v1/people/views/$uuid")->assertNoContent();
    }
}
