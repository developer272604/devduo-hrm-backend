<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = WorkspaceProvisioner::syncCatalog()['experience.survey.identifiable.view'];
        foreach (DB::table('roles')->where('name', 'Workspace administrator')->get(['id']) as $role) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'experience.survey.identifiable.view')->value('id');
        if ($permissionId) DB::table('permission_role')->where('permission_id', $permissionId)->delete();
    }
};
