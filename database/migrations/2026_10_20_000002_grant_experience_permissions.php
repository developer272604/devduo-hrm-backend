<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        // Identified survey access is granted separately by 000023, never by this broad HR grant.
        $all = array_values(array_filter(array_keys($ids), fn ($key) => str_starts_with($key, 'experience.') && $key !== 'experience.survey.identifiable.view'));
        foreach (DB::table('roles')->get() as $role) {
            $keys = ($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator'
                ? $all
                : ($role->name === 'Manager' ? ['experience.self', 'experience.view'] : ($role->name === 'Team member' ? ['experience.self'] : []));
            foreach ($keys as $key) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            if (isset($settings['modules']) && ! in_array('experience', $settings['modules'], true)) {
                $settings['modules'][] = 'experience';
                DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
            }
        }
    }

    public function down(): void {}
};
