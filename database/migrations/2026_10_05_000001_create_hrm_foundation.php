<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->json('settings')->nullable();
            $t->json('setup_draft')->nullable();
            $t->unsignedTinyInteger('setup_step')->default(1);
            $t->timestamp('setup_completed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('session_version')->default(1);
        });
        foreach (['legal_entities', 'locations', 'business_units', 'departments', 'teams', 'cost_centers', 'designations', 'positions', 'grades', 'levels', 'employee_types'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
                $t->string('name');
                $t->string('code', 40);
                $t->text('description')->nullable();
                $t->boolean('active')->default(true);
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'code']);
                $t->index(['tenant_id', 'active']);
            });
        }
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('description')->nullable();
            $t->boolean('system')->default(false);
            $t->string('data_scope')->default('self');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('group');
            $t->string('label');
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('work_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('source_module')->default('foundation');
            $t->string('source_entity')->nullable();
            $t->string('priority')->default('normal');
            $t->date('due_date')->nullable();
            $t->string('status')->default('open');
            $t->string('action')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'assignee_id', 'status']);
        });
        Schema::create('in_app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body');
            $t->string('category');
            $t->string('action')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'read_at']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('actor');
            $t->string('action');
            $t->string('module');
            $t->string('resource');
            $t->string('ip_address', 45)->nullable();
            $t->json('context')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['announcements', 'audit_logs', 'in_app_notifications', 'work_items', 'permission_role', 'role_user', 'permissions', 'roles', 'employee_types', 'levels', 'grades', 'positions', 'designations', 'cost_centers', 'teams', 'departments', 'business_units', 'locations', 'legal_entities'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn(['active', 'session_version']);
        });
        Schema::dropIfExists('tenants');
    }
};
