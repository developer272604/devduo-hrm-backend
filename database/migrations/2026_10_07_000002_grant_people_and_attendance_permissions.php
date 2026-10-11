<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the Phase 2/3 permission catalogue, enables the people and attendance modules for existing
 * workspaces, and creates the new default roles (HR administrator, Manager) where they do not exist.
 * Existing custom roles are never altered, except that the protected workspace administrator keeps full access.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();

        foreach (DB::table('tenants')->get() as $tenant) {
            $settings = json_decode($tenant->settings ?? '[]', true) ?: [];
            $settings['modules'] = array_values(array_unique(array_merge($settings['modules'] ?? [], ['people', 'attendance'])));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);

            foreach (WorkspaceProvisioner::defaultRoles() as $name => [$description, $scope, $system, $keys]) {
                $role = DB::table('roles')->where('tenant_id', $tenant->id)->where('name', $name)->first();
                if (! $role) {
                    $roleId = DB::table('roles')->insertGetId(['tenant_id' => $tenant->id, 'name' => $name, 'description' => $description, 'system' => $system, 'data_scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
                    $grant = $keys;
                } elseif ($role->system && $role->data_scope === 'tenant') {
                    $roleId = $role->id;
                    $grant = array_keys(WorkspaceProvisioner::PERMISSIONS);
                } elseif ($name === 'Team member') {
                    $roleId = $role->id;
                    $grant = ['people.directory'];
                } else {
                    continue;
                }
                foreach ($grant as $key) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $ids[$key]]);
                }
            }
        }
    }

    public function down(): void
    {
        // Access grants and settings are preserved on rollback.
    }
};
