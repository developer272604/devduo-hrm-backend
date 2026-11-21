<?php

namespace Tests\Feature\People;

use App\Domain\Security\LegalHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

final class LetterRetentionTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_preview_and_purge_obey_retention_cutoff_employee_and_letter_holds(): void
    {
        $this->bootTenant();
        $employee = $this->hire('Asha Rao');
        $other = $this->hire('Other Employee');
        $tenant = $this->admin->tenant_id;
        $this->signIn($this->admin);
        $template = $this->postJson('/api/v1/people/letter-templates', [
            'kind' => 'appointment', 'title' => 'Appointment', 'body' => 'Employee: {{employee_name}}',
        ])->assertCreated()->json('uuid');
        $issue = fn ($subject) => $this->postJson('/api/v1/employees/'.$subject->uuid.'/letters', ['template' => $template])->assertCreated()->json('uuid');
        $old = $issue($employee);
        $heldLetter = $issue($employee);
        $heldEmployee = $issue($other);
        $recent = $issue($employee);
        DB::table('hr_issued_letters')->whereIn('uuid', [$old, $heldLetter, $heldEmployee])->update(['created_at' => now()->subDays(31)]);

        $command = 'people:purge-letters';
        $this->artisan($command, ['tenant' => $tenant, '--apply' => true])
            ->expectsOutputToContain('no_enabled_purge_policy')->assertSuccessful();
        $this->assertDatabaseHas('hr_issued_letters', ['uuid' => $old]);

        $this->context($tenant);
        $security = app(LegalHoldService::class);
        $security->saveRetention($this->admin, ['data_category' => 'hr_issued_letters', 'module' => 'people',
            'retention_days' => 30, 'action' => 'purge', 'enabled' => true]);
        $security->place($this->admin, ['name' => 'Letter hold', 'reason' => 'Retain this letter', 'module' => 'people', 'subject_ref' => 'letter:'.strtoupper($heldLetter)]);
        $security->place($this->admin, ['name' => 'Employee hold', 'reason' => 'Retain employee letters', 'module' => 'people', 'subject_ref' => 'employee:'.$other->uuid]);

        $this->artisan($command, ['tenant' => $tenant])
            ->expectsOutputToContain('"eligible":1,"held":2,"purged":0')->assertSuccessful();
        $this->assertDatabaseHas('hr_issued_letters', ['uuid' => $old]);
        $global = $security->place($this->admin, ['name' => 'Global hold', 'reason' => 'Retain all records']);
        $this->artisan($command, ['tenant' => $tenant, '--apply' => true])
            ->expectsOutputToContain('"eligible":0,"held":3,"purged":0')->assertSuccessful();
        $security->release($this->admin, $global->uuid, 'Release global hold');
        $this->artisan($command, ['tenant' => $tenant, '--apply' => true])
            ->expectsOutputToContain('"eligible":1,"held":2,"purged":1')->assertSuccessful();
        $this->assertDatabaseMissing('hr_issued_letters', ['uuid' => $old]);
        foreach ([$heldLetter, $heldEmployee, $recent] as $uuid) {
            $this->assertDatabaseHas('hr_issued_letters', ['uuid' => $uuid]);
        }
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant, 'action' => 'hr_letter.retention_purged']);
    }
}
