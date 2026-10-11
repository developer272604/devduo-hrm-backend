<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Enables the performance module and grants performance.* keys: all to full-access system roles, all except feedback.reveal to HR administrator, the manager set to Manager, performance.self to Team member. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $all = array_values(array_filter(array_keys($ids), fn ($k) => str_starts_with($k, 'performance.')));
        $manager = ['performance.self', 'performance.view', 'performance.goals.manage', 'performance.goals.approve', 'performance.reviews.manage', 'performance.reports.view'];
        foreach (DB::table('roles')->get() as $role) {
            $full = $role->system && $role->data_scope === 'tenant';
            $keys = $full ? $all : ($role->name === 'HR administrator' ? array_values(array_diff($all, ['performance.feedback.reveal'])) : ($role->name === 'Manager' ? $manager : ($role->name === 'Team member' ? ['performance.self'] : [])));
            foreach ($keys as $key) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            if (isset($settings['modules']) && ! in_array('performance', $settings['modules'], true)) {
                $settings['modules'][] = 'performance';
                DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
            }
        }
    }

    public function down(): void {}
};
