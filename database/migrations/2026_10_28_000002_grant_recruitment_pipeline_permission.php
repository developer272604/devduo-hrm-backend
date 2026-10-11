<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Grants recruitment.pipeline.manage to full-access system roles and the default HR administrator. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        foreach (DB::table('roles')->get() as $role) {
            if (($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator') {
                DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids['recruitment.pipeline.manage']]);
            }
        }
    }

    public function down(): void {}
};
