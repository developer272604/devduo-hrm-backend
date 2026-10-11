<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        foreach (DB::table('roles')->get() as $role) {
            foreach ((WorkspaceProvisioner::defaultRoles()[$role->name][3] ?? []) as $key) {
                if (preg_match('/^(integrations\.|security\.access_reviews\.|intelligence\.)/', $key)) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
                }
            }
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['modules'] = array_values(array_unique([...($settings['modules'] ?? []), 'integrations', 'security', 'intelligence']));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('key', 'like', 'integrations.%')->orWhere('key', 'like', 'security.access_reviews.%')->orWhere('key', 'like', 'intelligence.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['modules'] = array_values(array_diff($settings['modules'] ?? [], ['integrations', 'security', 'intelligence']));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        }
    }
};
