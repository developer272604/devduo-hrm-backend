<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Imports\ImportProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportAndHealthTest extends IntegrationsTestCase
{
    private function profile(array $extra = []): string
    {
        $this->signIn($this->admin);

        return $this->postJson(self::API.'import-profiles', $extra + ['name' => 'Gate punches', 'kind' => 'attendance_punches', 'format' => 'csv', 'source' => 'upload',
            'mapping' => ['employee_number' => 'Emp No', 'timestamp' => 'Punch Time', 'direction' => 'Type']])->assertCreated()->json('uuid');
    }

    private function doRun(string $profile, string $name, string $content, bool $dry)
    {
        $this->signIn($this->admin);

        return $this->post(self::API."import-profiles/$profile/runs", ['file' => UploadedFile::fake()->createWithContent($name, $content), 'dry_run' => $dry ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private function punches(string $number): string
    {
        return "Emp No,Punch Time,Type\n$number,2026-10-06 09:00:00,IN\n$number,2026-10-06 18:00:00,OUT\nNOPE-1,2026-10-06 09:00:00,IN\n";
    }

    public function test_attendance_dry_run_writes_nothing_then_live_run_is_idempotent(): void
    {
        $asha = $this->hire('Asha Rao');
        $profile = $this->profile(['options' => ['accept_errors' => true]]);
        $csv = $this->punches($asha->employee_number);

        $dry = $this->doRun($profile, 'punches.csv', $csv, true)->assertCreated()->assertJsonPath('status', 'dry_run_errors')->assertJsonPath('total_rows', 3)->assertJsonPath('valid_rows', 2)->assertJsonPath('error_rows', 1);
        $this->assertSame(0, DB::table('attendance_events')->count());
        $items = $this->getJson(self::API.'import-runs/'.$dry->json('uuid').'/items')->assertOk()->json('data');
        $this->assertSame([4], array_column($items, 'row_number'));

        $live = $this->doRun($profile, 'punches.csv', $csv, false)->assertCreated()->assertJsonPath('status', 'completed_with_errors')->assertJsonPath('imported_rows', 2);
        $this->assertSame(2, DB::table('attendance_events')->count());
        // Same bytes again: the earlier run is returned, nothing is imported twice.
        $again = $this->doRun($profile, 'renamed.csv', $csv, false)->assertCreated();
        $this->assertSame($live->json('uuid'), $again->json('uuid'));
        $this->assertSame(2, DB::table('attendance_events')->count());
        $this->assertSame(2, DB::table('integration_job_runs')->count());
    }

    public function test_live_run_with_errors_fails_without_accept_errors_and_can_be_retried_after_fix(): void
    {
        $asha = $this->hire('Asha Rao');
        $profile = $this->profile();
        $failed = $this->doRun($profile, 'p.csv', $this->punches($asha->employee_number), false)->assertCreated()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'validation_errors');
        $this->assertSame(0, DB::table('attendance_events')->count());
        // Fix the problem at the source (the unknown employee now exists) and retry the same run.
        $this->context($this->admin->tenant_id);
        $this->hire('Nope Person', ['work_email' => 'nope@alpha.test']);
        DB::table('employees')->where('work_email', 'nope@alpha.test')->update(['employee_number' => 'NOPE-1']);
        $this->signIn($this->admin);
        $this->postJson(self::API.'import-runs/'.$failed->json('uuid').'/retry')->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('attempts', 2);
        $this->assertSame(3, DB::table('attendance_events')->count());
        $this->postJson(self::API.'import-runs/'.$failed->json('uuid').'/retry')->assertStatus(409);
    }

    public function test_employee_master_json_dry_run_and_live_import(): void
    {
        $profile = $this->profile(['name' => 'HRIS feed', 'kind' => 'employee_master', 'format' => 'json', 'mapping' => ['first_name' => 'given', 'last_name' => 'family', 'work_email' => 'mail', 'joined_on' => 'start',
            'country' => 'cc', 'timezone' => 'tz'], 'options' => []]);
        $json = json_encode(['data' => [['given' => 'Ira', 'family' => 'Menon', 'mail' => 'ira@alpha.test', 'start' => '2026-09-01', 'cc' => 'IN', 'tz' => 'Asia/Kolkata']]]);
        $before = DB::table('employees')->count();
        $dry = $this->doRun($profile, 'staff.json', $json, true)->assertCreated();
        $this->assertSame('dry_run_ok', $dry->json('status'), $dry->getContent());
        $this->assertSame($before, DB::table('employees')->count());
        $live = $this->doRun($profile, 'staff.json', $json, false)->assertCreated();
        $this->assertContains($live->json('status'), ['completed', 'completed_with_errors'], $live->getContent());
        $this->assertSame($before + 1, DB::table('employees')->count());
        $bad = $this->doRun($profile, 'bad.json', '{"not":"a list"}', true)->assertCreated()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'invalid_json');
        $this->assertNotEmpty($bad->json('error_message'));
        $this->postJson(self::API.'import-profiles', ['name' => 'x', 'kind' => 'employee_master', 'format' => 'csv', 'source' => 'upload', 'mapping' => ['salary' => 'Pay']])->assertStatus(422);
    }

    public function test_run_due_processes_drop_folder_once_and_archives_the_file(): void
    {
        $asha = $this->hire('Asha Rao');
        $profile = $this->profile(['source' => 'drop', 'interval_minutes' => 15, 'options' => ['accept_errors' => true]]);
        $this->context($this->admin->tenant_id);
        $dir = 'integrations/'.$this->admin->tenant_id.'/drop/'.$profile;
        Storage::disk('local')->put($dir.'/gate-1.csv', $this->punches($asha->employee_number));
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->assertSame(2, DB::table('attendance_events')->count());
        $this->assertSame([], Storage::disk('local')->files($dir));
        $this->assertCount(1, Storage::disk('local')->files($dir.'/processed'));
        // Dropping the same content again (device re-export) must not import it twice.
        Storage::disk('local')->put($dir.'/gate-1-copy.csv', $this->punches($asha->employee_number));
        $this->travel(16)->minutes();
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->artisan('integrations:run-due')->assertSuccessful();
        $this->assertSame(2, DB::table('attendance_events')->count());
        $this->assertSame(1, DB::table('integration_job_runs')->count());
    }

    public function test_failed_scheduled_run_is_retried_automatically_with_a_bound(): void
    {
        $profile = $this->profile(['source' => 'drop', 'interval_minutes' => 15]);
        $this->context($this->admin->tenant_id);
        DB::table('integration_job_runs')->count();
        $svc = app(ImportProfileService::class);
        $p = \App\Domain\Integrations\Models\ImportProfile::byUuid($profile);
        $run = $svc->run($p, "Emp No,Punch Time,Type\nX,2026-10-06 09:00:00,IN\n", 'x.csv', false, 'schedule', null);
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->next_retry_at, 'validation failures are not retried automatically');
    }

    public function test_health_center_never_fakes_provider_success_and_reports_real_state(): void
    {
        $this->signIn($this->admin);
        $res = $this->getJson(self::API.'health')->assertOk();
        $byKey = collect($res->json('adapters'))->keyBy('key');
        foreach (['sap', 'workday', 'zkteco', 'suprema', 'google_workspace', 'microsoft_365'] as $key) {
            $this->assertFalse($byKey[$key]['live']);
            $this->assertSame('not_live', $byKey[$key]['state']);
        }
        $this->assertTrue($byKey['public_api']['live']);
        $this->assertSame('ok', $res->json('status'));

        [, $key] = $this->makeClient(['rate_limit_per_minute' => 1]);
        $this->pub($key, '/api/public/v1/departments')->assertOk();
        $this->pub($key, '/api/public/v1/departments')->assertStatus(429);
        $this->signIn($this->admin);
        $health = $this->getJson(self::API.'health')->assertOk();
        $this->assertSame(1, $health->json('api.rate_limited_24h'));
        $this->assertSame(1, $health->json('api.active_clients'));
        $this->assertSame('degraded', $health->json('status'));

        $diag = collect($this->getJson(self::API.'diagnostics')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('pass', $diag['encryption']['status']);
        $this->assertSame('warn', $diag['providers']['status']);

        // The legacy connection test still reports not_configured, never success.
        $conn = $this->postJson(self::API.'connections', ['provider_key' => 'google_workspace', 'name' => 'Google'])->assertCreated()->json('uuid');
        $this->postJson(self::API."connections/$conn/test")->assertOk()->assertJsonPath('run.status', 'not_configured');
    }

    public function test_import_profiles_are_tenant_scoped_and_permissioned(): void
    {
        $profile = $this->profile();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->assertSame([], $this->getJson(self::API.'import-profiles')->json('data'));
        $this->patchJson(self::API."import-profiles/$profile", ['name' => 'hijack'])->assertNotFound();
        $this->doRun($profile, 'x.csv', "a,b\n1,2\n", true)->assertNotFound();
        $this->context($this->admin->tenant_id);
        $this->signIn($this->userWithRole('Team member', 'tm@beta.test'));
        $this->getJson(self::API.'import-profiles')->assertForbidden();
        $this->getJson(self::API.'health')->assertForbidden();
    }
}
