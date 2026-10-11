<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        // Existing tenants: roles that already export analytics may manage dashboards and schedules.
        $export = DB::table('permissions')->where('key', 'analytics.export')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $export)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $ids['analytics.dashboards.manage']]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'analytics.dashboards.manage')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
        }
    }
};
