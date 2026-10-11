<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the Phase 5 payroll.* permission catalogue for existing workspaces (mirrors 2026_10_12_000002_grant_leave_permissions).
 *   - Protected full-access roles (system, tenant scope) receive every payroll.* key.
 *   - Default "HR administrator", "Manager" and "Team member" receive ONLY the own-data self-service keys (WorkspaceProvisioner::PAYROLL_SELF).
 *   - Custom roles are never altered. Compensation / payroll administration is never granted implicitly to HR or managers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $all = array_values(array_filter(array_keys($ids), fn ($k) => str_starts_with($k, 'payroll.')));
        $self = WorkspaceProvisioner::PAYROLL_SELF;
        $selfRoles = ['HR administrator', 'Manager', 'Team member'];

        foreach (DB::table('tenants')->get() as $tenant) {
            $settings = json_decode($tenant->settings ?? '[]', true) ?: [];
            $settings['modules'] = array_values(array_unique(array_merge($settings['modules'] ?? [], ['payroll'])));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);

            foreach (DB::table('roles')->where('tenant_id', $tenant->id)->get() as $role) {
                $keys = ($role->system && $role->data_scope === 'tenant') ? $all : (in_array($role->name, $selfRoles, true) ? $self : []);
                foreach ($keys as $key) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
                }
            }
        }
    }

    public function down(): void
    {
        // Access grants and module settings are preserved on rollback.
    }
};
