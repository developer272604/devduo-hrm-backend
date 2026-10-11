<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Enables the lifecycle module and grants the new lifecycle.* keys: admin keys to full-access system roles + HR administrator, lifecycle.self to Manager and Team member. Custom roles untouched. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $admin = array_values(array_filter(array_keys($ids), fn ($k) => str_starts_with($k, 'lifecycle.')));
        foreach (DB::table('roles')->get() as $role) {
            $full = ($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator';
            $keys = $full ? $admin : (in_array($role->name, ['Manager', 'Team member'], true) ? ['lifecycle.self'] : []);
            foreach ($keys as $key) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            if (isset($settings['modules']) && ! in_array('lifecycle', $settings['modules'], true)) {
                $settings['modules'][] = 'lifecycle';
                DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
            }
        }
    }

    public function down(): void {}
};
