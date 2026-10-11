<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Phase 9 core: skills taxonomy (extends People skills/employee_skills), requirements, courses, paths, sessions, enrolments, assessments, certifications, mandatory rules, requests/budgets, feedback, development links. */
return new class extends Migration
{
    public function up(): void
    {
        $this->table('learning_skill_categories', function (Blueprint $t) {
            $t->string('code', 40); $t->string('name', 120); $t->boolean('active')->default(true); $t->unique(['tenant_id', 'code']);
        });
        $this->table('learning_proficiency_levels', function (Blueprint $t) {
            $t->unsignedTinyInteger('rank'); $t->string('name', 80); $t->string('description', 300)->nullable(); $t->unique(['tenant_id', 'rank']);
        });
        Schema::table('skills', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique(); $t->unsignedBigInteger('category_id')->nullable()->index(); $t->string('code', 40)->nullable(); $t->text('description')->nullable();
        });
        foreach (DB::table('skills')->whereNull('uuid')->pluck('id') as $id) DB::table('skills')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        Schema::table('employee_skills', function (Blueprint $t) {
            $t->unsignedTinyInteger('level_rank')->nullable(); $t->string('source', 16)->default('self'); // self|manager|assessed|training
            $t->unsignedBigInteger('validated_by')->nullable(); $t->dateTime('validated_at')->nullable();
        });
        $this->table('learning_skill_evidence', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('skill_id'); $t->string('kind', 24)->default('other'); $t->text('note')->nullable(); $t->string('reference', 255)->nullable();
            $t->unsignedBigInteger('created_by'); $t->index(['tenant_id', 'employee_id', 'skill_id'], 'learning_evid_idx');
        });
        $this->table('learning_skill_history', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id'); $t->unsignedBigInteger('skill_id'); $t->unsignedTinyInteger('from_rank')->nullable(); $t->unsignedTinyInteger('to_rank');
            $t->string('source', 16); $t->unsignedBigInteger('actor_id')->nullable(); $t->string('note', 300)->nullable(); $t->index(['tenant_id', 'employee_id', 'skill_id'], 'learning_hist_idx');
        });
        $this->table('learning_skill_requirements', function (Blueprint $t) {
            $t->unsignedBigInteger('designation_id'); $t->unsignedBigInteger('skill_id'); $t->unsignedTinyInteger('required_rank'); $t->boolean('mandatory')->default(true);
            $t->unique(['tenant_id', 'designation_id', 'skill_id'], 'learning_req_unique');
        });
        $this->table('learning_courses', function (Blueprint $t) {
            $t->string('code', 40); $t->string('title', 200); $t->text('description')->nullable(); $t->string('delivery', 16); // internal|external|online|classroom
            $t->string('provider', 120)->nullable(); $t->string('provider_ref', 120)->nullable(); $t->string('status', 16)->default('draft'); // draft|published|retired
            $t->decimal('duration_hours', 7, 2)->default(0); $t->decimal('cost', 14, 2)->default(0); $t->string('currency', 3)->default('INR');
            $t->unsignedInteger('capacity')->nullable(); $t->boolean('requires_approval')->default(false);
            $t->decimal('pass_percent', 5, 2)->nullable(); $t->decimal('min_attendance_pct', 5, 2)->default(0);
            $t->unsignedInteger('version')->default(1); $t->unsignedBigInteger('certification_id')->nullable(); $t->unsignedBigInteger('created_by');
            $t->unique(['tenant_id', 'code']); $t->index(['tenant_id', 'status']);
        });
        $this->table('learning_course_versions', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id'); $t->unsignedInteger('version'); $t->json('snapshot'); $t->unsignedBigInteger('created_by'); $t->unique(['course_id', 'version']);
        });
        $this->table('learning_course_skills', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('skill_id'); $t->unsignedTinyInteger('grants_rank'); $t->unique(['course_id', 'skill_id']);
        });
        $this->table('learning_course_prerequisites', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('prerequisite_id'); $t->unique(['course_id', 'prerequisite_id']);
        });
        $this->table('learning_paths', function (Blueprint $t) {
            $t->string('name', 160); $t->text('description')->nullable(); $t->string('status', 16)->default('draft'); $t->unsignedBigInteger('created_by');
        });
        $this->table('learning_path_items', function (Blueprint $t) {
            $t->unsignedBigInteger('path_id'); $t->unsignedBigInteger('course_id'); $t->unsignedInteger('position'); $t->boolean('required')->default(true); $t->unique(['path_id', 'course_id']);
        });
        $this->table('learning_sessions', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id')->index(); $t->string('code', 40); $t->date('starts_on'); $t->date('ends_on'); $t->string('location', 160)->nullable(); $t->string('instructor', 120)->nullable();
            $t->unsignedInteger('capacity')->nullable(); $t->boolean('waitlist_enabled')->default(true); $t->string('status', 16)->default('scheduled'); // scheduled|cancelled|completed
            $t->unique(['tenant_id', 'code']); $t->index(['tenant_id', 'starts_on']);
        });
        $this->table('learning_enrolments', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id')->index(); $t->unsignedBigInteger('session_id')->nullable()->index(); $t->unsignedBigInteger('employee_id')->index();
            $t->string('status', 20); // pending_approval|enrolled|waitlisted|in_progress|completed|failed|rejected|cancelled|no_show
            $t->string('source', 16)->default('self'); $t->unsignedBigInteger('requested_by')->nullable(); $t->unsignedBigInteger('decided_by')->nullable(); $t->dateTime('decided_at')->nullable(); $t->string('decision_note', 500)->nullable();
            $t->dateTime('waitlisted_at')->nullable(); $t->date('due_on')->nullable(); $t->decimal('score', 5, 2)->nullable(); $t->decimal('attendance_pct', 5, 2)->nullable(); $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('course_version')->default(1); $t->decimal('cost', 14, 2)->default(0); $t->string('currency', 3)->default('INR');
            $t->index(['tenant_id', 'status']); $t->index(['tenant_id', 'employee_id', 'course_id'], 'learning_enrol_emp_course');
        });
        $this->table('learning_attendance', function (Blueprint $t) {
            $t->unsignedBigInteger('enrolment_id'); $t->date('session_date'); $t->boolean('present'); $t->unsignedBigInteger('marked_by'); $t->unique(['enrolment_id', 'session_date']);
        });
        $this->table('learning_assessments', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id')->index(); $t->string('title', 200); $t->decimal('pass_percent', 5, 2)->default(70); $t->json('questions'); $t->boolean('active')->default(true);
        });
        $this->table('learning_attempts', function (Blueprint $t) {
            $t->unsignedBigInteger('assessment_id'); $t->unsignedBigInteger('enrolment_id')->nullable(); $t->unsignedBigInteger('employee_id'); $t->decimal('score', 8, 2); $t->decimal('max_score', 8, 2);
            $t->decimal('percent', 5, 2); $t->boolean('passed'); $t->json('answers'); $t->index(['tenant_id', 'employee_id']);
        });
        $this->table('learning_certifications', function (Blueprint $t) {
            $t->string('code', 40); $t->string('name', 160); $t->string('issuer', 160)->nullable(); $t->unsignedSmallInteger('validity_months')->nullable(); $t->unsignedBigInteger('renewal_course_id')->nullable();
            $t->json('reminder_days'); $t->boolean('active')->default(true); $t->unique(['tenant_id', 'code']);
        });
        $this->table('learning_employee_certs', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('certification_id'); $t->date('issued_on'); $t->date('expires_on')->nullable(); $t->string('status', 16)->default('active'); // active|expired|renewed|revoked
            $t->string('credential_id', 120)->nullable(); $t->unsignedBigInteger('enrolment_id')->nullable(); $t->unsignedBigInteger('renewed_by_id')->nullable();
            $t->unsignedInteger('last_reminder_days')->nullable(); $t->dateTime('last_reminded_at')->nullable(); $t->index(['tenant_id', 'status', 'expires_on'], 'learning_ecert_idx');
        });
        $this->table('learning_rules', function (Blueprint $t) {
            $t->string('name', 160); $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('designation_id')->nullable(); $t->unsignedBigInteger('department_id')->nullable(); $t->string('country', 2)->nullable();
            $t->unsignedSmallInteger('due_days')->default(30); $t->unsignedSmallInteger('recurrence_months')->nullable(); $t->unsignedSmallInteger('escalation_days')->default(0); $t->boolean('active')->default(true);
        });
        $this->table('learning_assignments', function (Blueprint $t) {
            $t->unsignedBigInteger('rule_id')->nullable(); $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('course_id'); $t->string('cycle_key', 40);
            $t->date('assigned_on'); $t->date('due_on'); $t->string('status', 16)->default('assigned'); // assigned|completed|waived
            $t->dateTime('completed_at')->nullable(); $t->dateTime('escalated_at')->nullable(); $t->dateTime('reminded_at')->nullable(); $t->unsignedBigInteger('enrolment_id')->nullable();
            $t->unique(['rule_id', 'employee_id', 'cycle_key'], 'learning_assign_unique'); $t->index(['tenant_id', 'status', 'due_on'], 'learning_assign_idx');
        });
        $this->table('learning_budgets', function (Blueprint $t) {
            $t->string('name', 160); $t->unsignedSmallInteger('fiscal_year'); $t->unsignedBigInteger('department_id')->nullable(); $t->decimal('amount', 16, 2); $t->string('currency', 3); $t->boolean('active')->default(true);
        });
        $this->table('learning_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('course_id')->nullable(); $t->string('title', 200); $t->string('provider', 160)->nullable(); $t->decimal('estimated_cost', 14, 2)->default(0);
            $t->string('currency', 3); $t->text('justification')->nullable(); $t->unsignedBigInteger('budget_id')->nullable(); $t->string('status', 16)->default('pending'); // pending|approved|rejected|cancelled
            $t->unsignedBigInteger('requested_by'); $t->unsignedBigInteger('decided_by')->nullable(); $t->dateTime('decided_at')->nullable(); $t->string('decision_note', 500)->nullable(); $t->index(['tenant_id', 'status']);
        });
        $this->table('learning_feedback', function (Blueprint $t) {
            $t->unsignedBigInteger('enrolment_id')->unique(); $t->unsignedBigInteger('employee_id'); $t->unsignedBigInteger('course_id')->index(); $t->unsignedTinyInteger('rating'); $t->text('comment')->nullable();
        });
        $this->table('learning_dev_links', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('course_id')->nullable(); $t->unsignedBigInteger('skill_id')->nullable(); $t->string('goal_ref', 36)->nullable(); $t->string('plan_ref', 36)->nullable();
            $t->date('target_date')->nullable(); $t->string('status', 16)->default('planned'); $t->unsignedBigInteger('created_by');
        });
    }

    private function table(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['learning_dev_links', 'learning_feedback', 'learning_requests', 'learning_budgets', 'learning_assignments', 'learning_rules', 'learning_employee_certs', 'learning_certifications', 'learning_attempts', 'learning_assessments',
            'learning_attendance', 'learning_enrolments', 'learning_sessions', 'learning_path_items', 'learning_paths', 'learning_course_prerequisites', 'learning_course_skills', 'learning_course_versions', 'learning_courses',
            'learning_skill_requirements', 'learning_skill_history', 'learning_skill_evidence', 'learning_proficiency_levels', 'learning_skill_categories'] as $t) Schema::dropIfExists($t);
    }
};
