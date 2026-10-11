<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->string('name');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('revision')->default(1);
            $t->json('draft');
            $t->unsignedBigInteger('published_version_id')->nullable();
            $t->timestamps();
        });
        Schema::create('workflow_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('definition_id')->constrained('workflow_definitions');
            $t->unsignedInteger('number');
            $t->json('spec');
            $t->foreignId('published_by')->constrained('users');
            $t->timestamps();
            $t->unique(['definition_id', 'number']);
        });
        Schema::create('workflow_instances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('definition_id')->constrained('workflow_definitions');
            $t->foreignId('version_id')->constrained('workflow_versions');
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('initiated_by')->constrained('users');
            $t->string('idempotency_key', 100);
            $t->string('status')->default('running');
            $t->unsignedInteger('step_index')->default(0);
            $t->json('context');
            $t->timestamp('due_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('failure_code')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'due_at']);
        });
        Schema::create('workflow_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('instance_id')->constrained('workflow_instances');
            $t->unsignedInteger('step_index');
            $t->foreignId('user_id')->constrained('users');
            $t->string('status')->default('pending');
            $t->text('reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['instance_id', 'step_index', 'user_id'], 'wf_assignment_unique');
        });
        Schema::create('workflow_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('instance_id')->constrained('workflow_instances');
            $t->unsignedInteger('step_index');
            $t->string('event');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->json('details')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['workflow_executions', 'workflow_assignments', 'workflow_instances', 'workflow_versions', 'workflow_definitions'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
