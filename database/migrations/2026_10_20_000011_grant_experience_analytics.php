<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = WorkspaceProvisioner::syncCatalog()['experience.analytics.view'];
        foreach (DB::table('roles')->get() as $role) {
            if (($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator') {
                DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void {}
};
