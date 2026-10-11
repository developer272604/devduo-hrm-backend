<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Enables the learning module and grants learning.* keys: all to full-access system roles and HR administrator, the manager set to Manager, learning.self to Team member. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $all = array_values(array_filter(array_keys($ids), fn ($k) => str_starts_with($k, 'learning.')));
        $manager = ['learning.self', 'learning.view', 'learning.skills.validate', 'learning.enrol.approve', 'learning.requests.approve', 'learning.reports.view'];
        foreach (DB::table('roles')->get() as $role) {
            $full = $role->system && $role->data_scope === 'tenant';
            $keys = $full ? $all : ($role->name === 'HR administrator' ? array_values($all) : ($role->name === 'Manager' ? $manager : ($role->name === 'Team member' ? ['learning.self'] : [])));
            foreach ($keys as $key) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            if (isset($settings['modules']) && ! in_array('learning', $settings['modules'], true)) {
                $settings['modules'][] = 'learning';
                DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
            }
        }
    }

    public function down(): void {}
};
