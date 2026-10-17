<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = ['analytics.impact.view', 'analytics.impact.manage'];

    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        foreach (DB::table('roles')->get() as $role) {
            foreach ((WorkspaceProvisioner::defaultRoles()[$role->name][3] ?? []) as $key) {
                if (in_array($key, self::KEYS, true)) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    }
};
