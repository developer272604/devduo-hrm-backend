<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 7 core: versioned checklist templates, lifecycle cases + tasks, effective-dated internal moves, confidential exit interviews, alumni. */
return new class extends Migration
{
    private function table(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t); $t->timestamps();
        });
    }

    public function up(): void
    {
        $this->table('lifecycle_templates', function (Blueprint $t) {
            $t->string('kind', 24); // onboarding|offboarding|transfer|promotion
            $t->string('name', 160);
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'kind']);
        });
        $this->table('lifecycle_template_versions', function (Blueprint $t) {
            $t->foreignId('template_id')->constrained('lifecycle_templates')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('draft'); // draft|published|retired
            $t->json('conditions')->nullable(); // {country, legal_entity_id, worker_type, separation_type}
            $t->dateTime('published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['template_id', 'version']);
            $t->index(['tenant_id', 'status']);
        });
        $this->table('lifecycle_template_tasks', function (Blueprint $t) {
            $t->foreignId('version_id')->constrained('lifecycle_template_versions')->restrictOnDelete();
            $t->string('group_name', 80)->default('General');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('owner_type', 16); // manager|hr|it|finance|employee|user
            $t->unsignedBigInteger('owner_user_id')->nullable(); // named owner for it/finance/user
            $t->string('due_anchor', 16)->default('joining'); // joining|last_day|effective
            $t->integer('due_offset_days')->default(0);
            $t->boolean('mandatory')->default(true);
            $t->string('clearance_dept', 40)->nullable(); // offboarding clearance department
            $t->boolean('requires_document')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->index(['tenant_id', 'version_id']);
        });
        $this->table('lifecycle_cases', function (Blueprint $t) {
            $t->string('kind', 24); // onboarding|offboarding|transfer|promotion
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('version_id')->nullable()->constrained('lifecycle_template_versions')->nullOnDelete();
            $t->string('status', 16)->default('open'); // open|completed|cancelled
            $t->date('anchor_date');
            $t->unsignedBigInteger('separation_record_id')->nullable();
            $t->unsignedBigInteger('move_id')->nullable();
            $t->string('source', 40)->default('manual');
            $t->string('settlement_uuid', 64)->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unique(['tenant_id', 'employee_id', 'kind', 'anchor_date'], 'lifecycle_cases_unique');
            $t->index(['tenant_id', 'kind', 'status']);
        });
        $this->table('lifecycle_tasks', function (Blueprint $t) {
            $t->foreignId('case_id')->constrained('lifecycle_cases')->restrictOnDelete();
            $t->unsignedBigInteger('template_task_id')->nullable();
            $t->string('group_name', 80); $t->string('title', 200); $t->text('description')->nullable();
            $t->string('owner_type', 16);
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_date')->nullable();
            $t->boolean('mandatory')->default(true);
            $t->string('clearance_dept', 40)->nullable();
            $t->boolean('requires_document')->default(false);
            $t->string('document_ref', 160)->nullable();
            $t->string('status', 16)->default('pending'); // pending|done|skipped
            $t->text('note')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->dateTime('escalated_at')->nullable();
            $t->index(['tenant_id', 'status', 'due_date']);
            $t->index(['tenant_id', 'owner_user_id', 'status']);
        });
        $this->table('lifecycle_moves', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('kind', 24); // transfer|promotion|manager_change|location_change
            $t->json('changes'); // assignment dimension ids and/or manager_uuid
            $t->date('effective_date');
            $t->text('reason')->nullable();
            $t->string('status', 16)->default('pending'); // pending|approved|rejected|applied|cancelled
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('decision_note')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->index(['tenant_id', 'status', 'effective_date']);
        });
        $this->table('lifecycle_exit_interviews', function (Blueprint $t) {
            $t->foreignId('case_id')->constrained('lifecycle_cases')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->longText('answers')->nullable(); // encrypted
            $t->string('status', 16)->default('pending'); // pending|submitted|purged
            $t->dateTime('submitted_at')->nullable();
            $t->date('retention_until')->nullable();
            $t->unique(['case_id']);
        });
        $this->table('lifecycle_alumni', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('last_working_date')->nullable();
            $t->string('separation_type', 24)->nullable();
            $t->boolean('rehire_eligible')->nullable();
            $t->text('rehire_note')->nullable();
            $t->unique(['employee_id']);
            $t->index(['tenant_id', 'person_id']);
        });
    }

    public function down(): void
    {
        foreach (['lifecycle_alumni', 'lifecycle_exit_interviews', 'lifecycle_moves', 'lifecycle_tasks', 'lifecycle_cases', 'lifecycle_template_tasks', 'lifecycle_template_versions', 'lifecycle_templates'] as $t) Schema::dropIfExists($t);
    }
};
