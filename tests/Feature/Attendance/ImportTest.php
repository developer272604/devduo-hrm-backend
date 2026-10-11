<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\People\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected Employee $asha;

    protected array $batches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        Storage::fake('attendance_private');
        config(['filesystems.disks.attendance_private' => ['driver' => 'local', 'root' => Storage::disk('attendance_private')->path('')]]);
        $this->asha = $this->hire('Asha Rao');
        $spy = new class($this->batches) implements AttendanceRecalculator
        {
            public function __construct(public array &$b) {}

            public function recalculateDay(int $e, string $d, string $t, ?int $by = null): ?int
            {
                return 1;
            }

            public function recalculate(array $s, string $t, ?int $by = null, ?string $k = null): string
            {
                $this->b[] = [$t, $s];

                return 'run';
            }
        };
        $this->app->instance(AttendanceRecalculator::class, $spy);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('punches.csv', $body);
    }

    private function upload(string $body, array $opts = [], array $mapping = null): string
    {
        $this->signIn($this->admin);
        $res = $this->post('/api/v1/attendance/imports', ['file' => $this->csv($body)], ['Accept' => 'application/json'])->assertCreated();
        $id = $res->json('data.id');
        $mapping ??= $res->json('suggested_mapping');
        $this->putJson("/api/v1/attendance/imports/$id/mapping", ['mapping' => $mapping, 'options' => $opts])->assertOk();

        return $id;
    }

    public function test_happy_path_errors_duplicates_commit_and_single_recalculation(): void
    {
        $body = "employee_number,timestamp,direction,external_event_id\n"
            .$this->asha->employee_number.",2026-10-06 09:00:00,IN,A1\n"
            .$this->asha->employee_number.",2026-10-06 18:00:00,OUT,A2\n"
            .$this->asha->employee_number.",2026-10-06 09:00:00,IN,A1\n"
            ."EMP-99999,2026-10-06 09:00:00,IN,A9\n"
            .$this->asha->employee_number.",not a date,IN,A8\n";
        $id = $this->upload($body, ['timezone' => 'Asia/Kolkata']);
        $show = $this->getJson("/api/v1/attendance/imports/$id")->assertOk();
        $this->assertSame(2, $show->json('data.counts.valid'));
        $this->assertSame(2, $show->json('data.counts.errors'));
        $this->assertSame(1, $show->json('data.counts.duplicates'));
        $this->assertStringContainsString("couldn't match", $show->json('data.message'));
        $this->postJson("/api/v1/attendance/imports/$id/commit")->assertStatus(202);
        $this->signIn($this->admin);
        $done = $this->getJson("/api/v1/attendance/imports/$id")->json('data');
        $this->assertSame('completed_with_errors', $done['status']);
        $this->assertSame(2, $done['counts']['imported']);
        $this->assertCount(1, $this->batches);
        $this->assertSame('import', $this->batches[0][0]);
        $this->context($this->admin->tenant_id);
        $e = AttendanceEvent::where('external_ref', 'A1')->first();
        $this->assertSame('2026-10-06 03:30:00', $e->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('import', $e->source);
        $this->assertSame('2026-10-06 09:00:00', $e->raw_metadata['import']['original']['timestamp'] ?? null);
        $this->signIn($this->admin);
        $csv = $this->get("/api/v1/attendance/imports/$id/errors.csv")->assertOk()->streamedContent() ?: file_get_contents($this->get("/api/v1/attendance/imports/$id/errors.csv")->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('EMP-99999', $csv);
        // idempotent re-commit
        $this->postJson("/api/v1/attendance/imports/$id/retry")->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, AttendanceEvent::count());
    }

    public function test_date_format_required_for_ambiguous_dates_and_dst_warning(): void
    {
        $n = $this->asha->employee_number;
        $id = $this->upload("employee_number,timestamp,direction\n$n,03/04/2026 09:00,IN\n");
        $this->assertSame(1, $this->getJson("/api/v1/attendance/imports/$id")->json('data.counts.errors'));
        $id2 = $this->upload("employee_number,timestamp,direction\n$n,2026-04-05 02:30:00,IN\n", ['timezone' => 'Australia/Sydney']);
        $this->assertSame(1, $this->getJson("/api/v1/attendance/imports/$id2")->json('data.counts.warnings'));
        $rows = $this->getJson("/api/v1/attendance/imports/$id2/rows")->json('data');
        $this->assertStringContainsString('twice', $rows[0]['warnings'][0]);
        $id3 = $this->upload("employee_number,timestamp,direction\n$n,03/04/2026 09:00,IN\n", ['date_format' => 'd/m/Y H:i', 'timezone' => 'Asia/Kolkata']);
        $this->assertSame(1, $this->getJson("/api/v1/attendance/imports/$id3")->json('data.counts.valid'));
    }

    public function test_limits_template_permission_and_tenant_isolation(): void
    {
        $this->signIn($this->admin);
        $this->post('/api/v1/attendance/imports', ['file' => UploadedFile::fake()->createWithContent('x.csv', "")], ['Accept' => 'application/json'])->assertStatus(422);
        $this->get('/api/v1/attendance/imports/template?kind=device_events')->assertOk();
        $id = $this->upload("employee_number,timestamp\n{$this->asha->employee_number},2026-10-06 09:00:00\n");
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/attendance/imports/$id")->assertNotFound();
        $this->context($this->admin->tenant_id);
        $ashaUser = $this->userWithRole('Team member', 'tm@beta.test');
        $this->signIn($ashaUser);
        $this->getJson('/api/v1/attendance/imports')->assertForbidden();
    }

    public function test_device_events_import_uses_mappings(): void
    {
        $t = Tenant::find($this->admin->tenant_id);
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/attendance/devices', ['name' => 'D', 'type' => 'biometric', 'device_identifier' => 'D1', 'timezone' => 'Asia/Kolkata', 'provider_key' => 'file_import'])->json('data.id');
        $this->postJson("/api/v1/attendance/devices/$uuid/mappings", ['employee_id' => $this->asha->uuid, 'device_employee_id' => '55', 'effective_from' => '2026-01-01'])->assertCreated();
        $res = $this->post('/api/v1/attendance/imports', ['file' => $this->csv("device_employee_id,timestamp,direction\n55,2026-10-06 09:00:00,IN\n77,2026-10-06 09:00:00,IN\n"), 'kind' => 'device_events', 'device_id' => $uuid], ['Accept' => 'application/json'])->assertCreated();
        $id = $res->json('data.id');
        $this->putJson("/api/v1/attendance/imports/$id/mapping", ['mapping' => $res->json('suggested_mapping')])->assertOk()->assertJsonPath('data.counts.valid', 1)->assertJsonPath('data.counts.errors', 1);
    }
}
