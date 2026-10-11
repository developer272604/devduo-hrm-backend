<?php
namespace Tests\Feature\Experience;
use Tests\Feature\Performance\PerformanceTestCase;
use Illuminate\Support\Facades\DB;
class SurveyPermissionMigrationTest extends PerformanceTestCase {
    public function test_pending_migration_chain_does_not_grant_hr_identified_survey_access(): void {
        $hr = $this->userWithRole('HR administrator', 'hr.migration@alpha.test');
        $id = DB::table('permissions')->where('key', 'experience.survey.identifiable.view')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        (require database_path('migrations/2026_10_20_000002_grant_experience_permissions.php'))->up();
        $this->assertFalse($hr->allows('experience.survey.identifiable.view'));
        (require database_path('migrations/2026_10_20_000023_grant_identifiable_survey_permission.php'))->up();
        $this->assertFalse($hr->allows('experience.survey.identifiable.view'));
        $this->assertTrue($this->admin->allows('experience.survey.identifiable.view'));
    }
}
