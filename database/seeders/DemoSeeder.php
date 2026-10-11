<?php

namespace Database\Seeders;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo data is only available in local and testing environments.');
        }
        if (Tenant::where('slug', 'devduo')->exists()) {
            return;
        }
        $u = app(WorkspaceProvisioner::class)->create('Devduo Studio', 'devduo', 'admin@devduo.test', 'DevduoDemo2026!');
        app(TenantContext::class)->id = $u->tenant_id;
        app(TaskService::class)->create(['assignee_id' => $u->id, 'title' => 'Complete your company setup', 'description' => 'Add your company details, departments and work week.', 'priority' => 'high', 'due_date' => today()->addDays(2)->toDateString(), 'action' => '/setup']);
        Announcement::create(['title' => 'A fresh start for your people operations', 'body' => 'Welcome to your development workspace. Complete company setup, invite your administrators, and make this space your own.']);
        app(TenantContext::class)->id = null;
    }
}
