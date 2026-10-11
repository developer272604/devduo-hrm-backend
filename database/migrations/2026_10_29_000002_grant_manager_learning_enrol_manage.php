<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Managers may assign courses to, mark attendance for and complete training of their (data-scoped) reports. Idempotent grant to the Manager role. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        foreach (DB::table('roles')->where('name', 'Manager')->get() as $role) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids['learning.enrol.manage']]);
        }
    }

    public function down(): void {}
};
