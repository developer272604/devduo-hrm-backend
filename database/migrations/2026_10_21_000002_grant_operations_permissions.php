<?php
use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        $ids = WorkspaceProvisioner::syncCatalog();
        $defaults = WorkspaceProvisioner::defaultRoles();
        foreach (DB::table('roles')->get() as $role) {
            $keys = $defaults[$role->name][3] ?? [];
            foreach ($keys as $key) if (preg_match('/^(assets|expenses|travel)\./', $key)) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['modules'] = array_values(array_unique([...($settings['modules'] ?? []), 'assets', 'expenses', 'travel']));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        }
    }
    public function down(): void {
        $ids = DB::table('permissions')->where('key', 'like', 'assets.%')->orWhere('key', 'like', 'expenses.%')->orWhere('key', 'like', 'travel.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['modules'] = array_values(array_diff($settings['modules'] ?? [], ['assets', 'expenses', 'travel']));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        }
    }
};
