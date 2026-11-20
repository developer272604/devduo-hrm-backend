<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->timestamps();
        });
        Schema::create('work_project_members', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('work_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });
        Schema::create('work_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('uuid')->unique();
            $table->date('week_start');
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('entries');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'week_start']);
            $table->index(['tenant_id', 'status', 'week_start']);
        });
        $ids = WorkspaceProvisioner::syncCatalog();
        $defaults = WorkspaceProvisioner::defaultRoles();
        foreach (DB::table('roles')->get() as $role) {
            if (in_array('projects.manage', $defaults[$role->name][3] ?? [], true)) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $ids['projects.manage']]);
            }
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('key', 'projects.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        DB::table('permissions')->where('id', $permission)->delete();
        Schema::dropIfExists('work_timesheets');
        Schema::dropIfExists('work_project_members');
        Schema::dropIfExists('work_projects');
    }
};

