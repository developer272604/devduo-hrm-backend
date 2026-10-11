<?php
use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Grants the Phase 11 depth permissions to roles that already manage the related area (administrators and HR); managers get none. */
return new class extends Migration {
    private const NEW = ['travel.manage', 'expenses.fx.manage', 'expenses.fx.approve', 'operations.reports.view', 'operations.reports.export'];
    public function up(): void {
        $ids = WorkspaceProvisioner::syncCatalog();
        $defaults = WorkspaceProvisioner::defaultRoles();
        foreach (DB::table('roles')->get() as $role) {
            foreach (self::NEW as $key) if (in_array($key, $defaults[$role->name][3] ?? [], true)) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
    }
    public function down(): void {
        DB::table('permission_role')->whereIn('permission_id', DB::table('permissions')->whereIn('key', self::NEW)->pluck('id'))->delete();
    }
};
