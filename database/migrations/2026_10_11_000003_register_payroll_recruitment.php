<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        foreach (DB::table('tenants')->get() as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['modules'] = array_values(array_unique([...($settings['modules'] ?? []), 'payroll', 'recruitment']));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
            // Existing custom/HR roles do not acquire compensation access implicitly.
            foreach (DB::table('roles')->where('tenant_id', $tenant->id)->where('system', true)->where('data_scope', 'tenant')->get() as $role) {
                foreach ($ids as $key => $id) if (str_starts_with($key, 'payroll.') || str_starts_with($key, 'recruitment.')) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $id]);
            }
        }
    }
    public function down(): void { /* Keep explicitly provisioned access settings. */ }
};
