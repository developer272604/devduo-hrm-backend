<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Grants the new recruitment.* keys to full-access system roles and the default HR administrator (not confidential access). Custom roles untouched. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = WorkspaceProvisioner::syncCatalog();
        $new = ['recruitment.offer.manage', 'recruitment.offer.approve', 'recruitment.handover', 'recruitment.reports.view', 'recruitment.privacy.manage'];
        foreach (DB::table('roles')->get() as $role) {
            $grant = ($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator';
            if (! $grant) continue;
            foreach ($new as $key) DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids[$key]]);
        }
    }

    public function down(): void {}
};
