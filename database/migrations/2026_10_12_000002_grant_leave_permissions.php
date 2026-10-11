<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the leave.* permission catalogue and enables the leave module for existing workspaces (mirrors 2026_10_07_000002).
 *   - Protected full-access roles (system, tenant scope) and the default "HR administrator" role get every leave.* key.
 *   - Default "Manager" gets team visibility, approval and reports; default "Team member" gets leave.request.
 *   - Custom roles are never altered; leave.medical.view is never granted to Manager / Team member.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $leave = array_values(array_filter(array_keys($ids), fn ($k) => str_starts_with($k, 'leave.')));
        $grants = [
            'Manager' => ['leave.view', 'leave.request', 'leave.team.view', 'leave.approve', 'leave.reports.view'],
            'Team member' => ['leave.view', 'leave.request'],
            'HR administrator' => $leave,
        ];

        foreach (DB::table('tenants')->get() as $tenant) {
            $settings = json_decode($tenant->settings ?? '[]', true) ?: [];
            $settings['modules'] = array_values(array_unique(array_merge($settings['modules'] ?? [], ['leave'])));
            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);

            foreach (DB::table('roles')->where('tenant_id', $tenant->id)->get() as $role) {
                $keys = ($role->system && $role->data_scope === 'tenant') ? $leave : ($grants[$role->name] ?? []);
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
