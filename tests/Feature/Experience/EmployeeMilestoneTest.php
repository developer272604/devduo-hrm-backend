<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class EmployeeMilestoneTest extends PerformanceTestCase
{
    public function test_birthdays_and_work_anniversaries_are_private_opt_out_aware_and_idempotent(): void
    {
        $celebrating = $this->hire('Nina Milestone', ['role' => 'Team member', 'email' => 'nina.milestone@alpha.test', 'dob' => '1990-10-07', 'joined_on' => '2020-10-07']);
        $celebratingUserId = $this->userOf($celebrating)->id;
        $optedOut = $this->hire('Omar Private', ['role' => 'Team member', 'email' => 'omar.private@alpha.test', 'dob' => '1992-10-07', 'joined_on' => '2023-01-05']);
        $optedOutUserId = $this->userOf($optedOut)->id;
        $this->actAs($optedOut);
        $this->putJson('/api/v1/experience/communication-preferences', ['channels' => ['events' => ['in_app' => false]]])->assertOk();

        $this->artisan('experience:send-milestones', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $this->artisan('experience:send-milestones', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $this->assertSame(1, \DB::table('in_app_notifications')->where('user_id', $celebratingUserId)->where('title', 'Happy birthday!')->count());
        $this->assertSame(1, \DB::table('in_app_notifications')->where('user_id', $celebratingUserId)->where('title', 'Happy work anniversary')->count());
        $this->assertSame(0, \DB::table('in_app_notifications')->where('user_id', $this->userOf($this->emp1)->id)->whereIn('title', ['Happy birthday!', 'Happy work anniversary'])->count());
        $this->assertSame(0, \DB::table('in_app_notifications')->where('user_id', $optedOutUserId)->where('title', 'Happy birthday!')->count());
        $this->assertSame(1, \DB::table('experience_milestone_deliveries')->where('user_id', $optedOutUserId)->where('milestone_type', 'birthday')->whereNotNull('suppressed_at')->count());
        $this->assertSame(2, \DB::table('experience_milestone_deliveries')->where('user_id', $celebratingUserId)->whereNotNull('sent_at')->count());
    }
}
